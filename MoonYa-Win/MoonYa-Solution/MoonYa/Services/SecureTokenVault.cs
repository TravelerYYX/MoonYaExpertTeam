using System;
using System.IO;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using ModelContextProtocol.Authentication;

namespace MoonYa.Services
{
    /// <summary>
    /// Per-Windows-user encrypted storage for MCP OAuth tokens and stdio secrets.
    /// The database only ever needs to retain the opaque VaultKey.
    /// </summary>
    public sealed class SecureTokenVault
    {
        private readonly string _root;
        private static readonly JsonSerializerOptions JsonOptions = new()
        {
            PropertyNamingPolicy = JsonNamingPolicy.CamelCase
        };

        public SecureTokenVault()
        {
            _root = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "MoonYa",
                "mcp-vault");
            Directory.CreateDirectory(_root);
        }

        public string GetVaultKey(long userId, string serverKey) =>
            $"moonya:{userId}:{serverKey}";

        public Task StoreJsonAsync<T>(string vaultKey, T value, CancellationToken cancellationToken = default)
        {
            cancellationToken.ThrowIfCancellationRequested();
            var plaintext = JsonSerializer.SerializeToUtf8Bytes(value, JsonOptions);
            var encrypted = ProtectedData.Protect(plaintext, Entropy(vaultKey), DataProtectionScope.CurrentUser);
            var target = PathFor(vaultKey);
            var temporary = target + "." + Guid.NewGuid().ToString("N") + ".tmp";
            File.WriteAllBytes(temporary, encrypted);
            File.Move(temporary, target, true);
            return Task.CompletedTask;
        }

        public Task<T?> GetJsonAsync<T>(string vaultKey, CancellationToken cancellationToken = default)
        {
            cancellationToken.ThrowIfCancellationRequested();
            var path = PathFor(vaultKey);
            if (!File.Exists(path))
            {
                return Task.FromResult<T?>(default);
            }

            try
            {
                var encrypted = File.ReadAllBytes(path);
                var plaintext = ProtectedData.Unprotect(encrypted, Entropy(vaultKey), DataProtectionScope.CurrentUser);
                return Task.FromResult(JsonSerializer.Deserialize<T>(plaintext, JsonOptions));
            }
            catch (CryptographicException)
            {
                return Task.FromResult<T?>(default);
            }
            catch (JsonException)
            {
                return Task.FromResult<T?>(default);
            }
        }

        public Task DeleteAsync(string vaultKey)
        {
            var path = PathFor(vaultKey);
            if (File.Exists(path))
            {
                File.Delete(path);
            }
            return Task.CompletedTask;
        }

        private string PathFor(string vaultKey)
        {
            var hash = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(vaultKey))).ToLowerInvariant();
            return Path.Combine(_root, hash + ".vault");
        }

        private static byte[] Entropy(string vaultKey) =>
            SHA256.HashData(Encoding.UTF8.GetBytes("MoonYa.MCP.Vault.v1|" + vaultKey));
    }

    public sealed class DpapiTokenCache : ITokenCache
    {
        private readonly SecureTokenVault _vault;
        private readonly string _vaultKey;

        public DpapiTokenCache(SecureTokenVault vault, string vaultKey)
        {
            _vault = vault;
            _vaultKey = vaultKey;
        }

        public ValueTask StoreTokensAsync(TokenContainer tokens, CancellationToken cancellationToken) =>
            new(_vault.StoreJsonAsync(_vaultKey, tokens, cancellationToken));

        public ValueTask<TokenContainer?> GetTokensAsync(CancellationToken cancellationToken) =>
            new(_vault.GetJsonAsync<TokenContainer>(_vaultKey, cancellationToken));
    }
}