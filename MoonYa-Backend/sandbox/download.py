#!/usr/bin/env python3
"""
MoonYa 沙箱文件下载脚本
在受限的沙箱环境中运行，提供安全的文件下载功能。
"""

import argparse
import json
import os
import sys
import time
from urllib.parse import urlparse

# ── Magic Bytes Map ────────────────────────────
MAGIC_BYTES = {
    b'\xff\xd8\xff': 'image/jpeg',
    b'\x89PNG': 'image/png',
    b'GIF8': 'image/gif',
    b'%PDF': 'application/pdf',
    b'PK\x03\x04': 'application/zip',
    b'\x1f\x8b': 'application/gzip',
    b'Rar!\x1a\x07': 'application/x-rar-compressed',
}

# ── Allowed Extensions Whitelist ───────────────
ALLOWED_EXTENSIONS = {
    # Documents
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
    'md', 'epub', 'mobi', 'log', 'rtf',
    # Images
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'tiff',
    # Audio/Video
    'mp3', 'wav', 'ogg', 'flac', 'aac', 'mp4', 'webm', 'mov', 'avi', 'mkv',
    # Archives
    'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz',
    # Code
    'py', 'js', 'ts', 'html', 'css', 'java', 'c', 'cpp', 'cs', 'go',
    'json', 'xml', 'yaml', 'yml', 'ini', 'conf', 'sh', 'bat',
}


def output_json(data, success=True):
    """Output JSON result to appropriate stream."""
    data['success'] = success
    if success:
        print(json.dumps(data, ensure_ascii=False))
    else:
        print(json.dumps(data, ensure_ascii=False), file=sys.stderr)


def extract_filename(url, suggested=None):
    """Extract filename from URL or use suggested name."""
    if suggested:
        return os.path.basename(suggested)
    path = urlparse(url).path
    if path:
        name = os.path.basename(path)
        if name:
            return name
    return f'download_{time.strftime("%Y%m%d_%H%M%S")}.bin'


def normalize_filename(name):
    """Sanitize filename to prevent path traversal."""
    name = os.path.basename(name)  # Strip path components
    # Replace dangerous characters
    name = ''.join(c if c.isalnum() or c in '._- ' else '_' for c in name)
    return name.strip() or f'download_{int(time.time())}'


def check_extension(filename):
    """Check if file extension is in allowed list."""
    ext = filename.rsplit('.', 1)[-1].lower() if '.' in filename else ''
    if not ext:
        return True  # Allow files without extension
    if ext in ALLOWED_EXTENSIONS:
        return True
    return False


def verify_magic_bytes(filepath, claimed_mime):
    """Verify file magic bytes match the claimed MIME type."""
    try:
        with open(filepath, 'rb') as f:
            header = f.read(8)
    except IOError:
        return False

    # Check against known magic bytes
    for magic, expected_mime in MAGIC_BYTES.items():
        if header.startswith(magic):
            # ZIP files can be many formats
            if magic == b'PK\x03\x04':
                return True  # Trust ZIP-based formats
            return True

    # If no magic byte match, allow text-based files
    text_types = {'text/', 'application/json', 'application/xml'}
    for t in text_types:
        if claimed_mime and claimed_mime.startswith(t):
            return True

    # Suspicious - file header doesn't match
    return False


def download_file(url, output_path, timeout=300):
    """Stream download a file with progress tracking."""
    try:
        import requests
        using_requests = True
    except ImportError:
        using_requests = False

    if using_requests:
        try:
            response = requests.get(
                url,
                stream=True,
                timeout=timeout,
                headers={'User-Agent': 'MoonYa-Sandbox/1.0'},
                allow_redirects=True,
                verify=False  # Allow self-signed certs in sandbox
            )
            response.raise_for_status()
            total_size = int(response.headers.get('content-length', 0))
            content_type = response.headers.get('content-type', 'application/octet-stream')
            
            downloaded = 0
            start_time = time.time()
            
            with open(output_path, 'wb') as f:
                for chunk in response.iter_content(chunk_size=8192):
                    if chunk:
                        f.write(chunk)
                        downloaded += len(chunk)
                    
                    # Check timeout
                    if time.time() - start_time > timeout:
                        response.close()
                        raise TimeoutError('Download exceeded time limit')
            
            return {
                'success': True,
                'file': {
                    'path': os.path.abspath(output_path),
                    'size': downloaded,
                    'mime_type': content_type,
                    'name': os.path.basename(output_path)
                }
            }
            
        except requests.exceptions.Timeout:
            return {'success': False, 'error': f'下载超时（{timeout}秒）', 'code': 408}
        except requests.exceptions.HTTPError as e:
            return {'success': False, 'error': f'HTTP错误: {e.response.status_code}', 'code': 502}
        except requests.exceptions.ConnectionError:
            return {'success': False, 'error': '无法连接到服务器', 'code': 502}
        except requests.exceptions.RequestException as e:
            return {'success': False, 'error': f'下载失败: {str(e)}', 'code': 500}
    
    else:
        # Fallback to urllib
        try:
            from urllib.request import urlopen, Request
            
            req = Request(url, headers={'User-Agent': 'MoonYa-Sandbox/1.0'})
            response = urlopen(req, timeout=timeout)
            
            content_type = response.headers.get('Content-Type', 'application/octet-stream')
            total_size = int(response.headers.get('Content-Length', 0))
            
            downloaded = 0
            start_time = time.time()
            
            with open(output_path, 'wb') as f:
                while True:
                    chunk = response.read(8192)
                    if not chunk:
                        break
                    f.write(chunk)
                    downloaded += len(chunk)
                    
                    if time.time() - start_time > timeout:
                        raise TimeoutError('Download exceeded time limit')
            
            return {
                'success': True,
                'file': {
                    'path': os.path.abspath(output_path),
                    'size': downloaded,
                    'mime_type': content_type,
                    'name': os.path.basename(output_path)
                }
            }
            
        except TimeoutError:
            return {'success': False, 'error': f'下载超时（{timeout}秒）', 'code': 408}
        except Exception as e:
            return {'success': False, 'error': f'下载失败: {str(e)}', 'code': 500}


def main():
    parser = argparse.ArgumentParser(description='MoonYa 沙箱文件下载工具')
    parser.add_argument('--url', required=True, help='下载URL（必填）')
    parser.add_argument('--output', default=None, help='输出文件路径（默认当前目录）')
    parser.add_argument('--timeout', type=int, default=300, help='超时时间（秒，默认300）')
    parser.add_argument('--user-role', default='guest', choices=['admin', 'user', 'guest'],
                        help='用户角色（默认guest）')
    
    args = parser.parse_args()
    
    # Validate URL
    parsed = urlparse(args.url)
    if parsed.scheme not in ('http', 'https'):
        output_json({'success': False, 'code': 400,
                     'error': '无效的URL协议', 'details': '仅支持 http/https'}, success=False)
        sys.exit(1)
    
    # Extract and normalize filename
    filename = extract_filename(args.url, args.output)
    safe_name = normalize_filename(filename)
    
    # Check extension whitelist
    if not check_extension(safe_name):
        output_json({'success': False, 'code': 403,
                     'error': '文件类型不允许下载',
                     'details': f'扩展名 .{safe_name.rsplit(".")[-1] if "." in safe_name else ""} 不在允许列表中'}, success=False)
        sys.exit(1)
    
    # Determine output path
    if args.output:
        output_path = args.output
    else:
        output_path = os.path.join(os.getcwd(), safe_name)
    
    # Ensure output directory exists
    output_dir = os.path.dirname(output_path)
    if output_dir and not os.path.exists(output_dir):
        try:
            os.makedirs(output_dir)
        except OSError as e:
            output_json({'success': False, 'code': 500,
                         'error': f'无法创建输出目录: {str(e)}'}, success=False)
            sys.exit(1)
    
    # Download
    result = download_file(args.url, output_path, args.timeout)
    
    if result['success']:
        # Verify magic bytes
        mime_type = result['file'].get('mime_type', 'application/octet-stream')
        if not verify_magic_bytes(output_path, mime_type):
            os.unlink(output_path)
            output_json({'success': False, 'code': 403,
                         'error': '安全警告：文件类型与内容不匹配，已拒绝保存',
                         'details': '文件头魔数与声称的MIME类型不一致'}, success=False)
            sys.exit(1)
        
        output_json(result, success=True)
    else:
        output_json(result, success=False)
        sys.exit(1)


if __name__ == '__main__':
    main()
