        /* 图片上传容器 */
        .upload-container {
            display: flex;
            gap: 8px;
            z-index: 20;
            flex-wrap: wrap;
            margin-bottom: 10px;
            padding: 0 5px;
        }

        /* 本地路径附件：只显示并传递路径，不读取或上传文件内容。 */
        .local-path-item {
            display: inline-flex;
            max-width: min(260px, 100%);
            height: 30px;
            align-items: center;
            gap: 6px;
            padding: 0 6px 0 8px;
            border: 1px solid #dce7f7;
            border-radius: 8px;
            background: #f5f9ff;
            color: #3271bb;
        }

        .local-path-item svg {
            width: 15px;
            height: 15px;
            flex: 0 0 15px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .local-path-name {
            overflow: hidden;
            min-width: 0;
            color: #3d5f88;
            font-size: 12px;
            line-height: 1;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .local-path-remove {
            display: grid;
            width: 17px;
            height: 17px;
            flex: 0 0 17px;
            place-items: center;
            padding: 0;
            border: 0;
            border-radius: 50%;
            background: transparent;
            color: #6f88a7;
            font: 17px/1 Arial, sans-serif;
            cursor: pointer;
        }

        .local-path-remove:hover,
        .local-path-remove:focus-visible {
            background: #dbeafe;
            color: #1d5fa7;
            outline: none;
        }

        /* 上传图片项 */
        .upload-item {
            position: relative;
            width: 34px;
            height: 34px;
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid #e8e8e8;
        }

        .upload-item .pdf-icon-item {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-color: #fff2f0;
            padding: 2px;
        }

        .upload-item .pdf-icon-item svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }

        .upload-item .pdf-name {
            font-size: 6px;
            color: #ff4d4f;
            max-width: 30px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            line-height: 1;
        }

        /* 上传图片 */
        .upload-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* 删除按钮 */
        .upload-item .delete-btn {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 16px;
            height: 16px;
            background-color: #fff;
            border-radius: 50%;
            border: 1px solid #e8e8e8;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 10px;
            color: #ff4d4f;
            z-index: 10;
        }

        .upload-item:hover .delete-btn {
            display: flex;
        }

        /* 上传进度项 */
        .upload-progress {
            position: relative;
            width: 34px;
            height: 34px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e8e8e8;
            background-color: #fff;
        }

        /* 环形进度条 */
        .progress-ring {
            position: absolute;
            width: 100%;
            height: 100%;
        }

        .progress-ring-circle {
            stroke: #e8e8e8;
            stroke-width: 2;
            fill: none;
        }

        .progress-ring-circle-active {
            stroke: #1890ff;
            stroke-width: 2;
            fill: none;
            stroke-linecap: round;
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
            transition: stroke-dashoffset 0.1s;
        }

        /* 进度百分比 */
        .progress-text {
            font-size: 10px;
            color: #1890ff;
            font-weight: 500;
            z-index: 5;
        }
