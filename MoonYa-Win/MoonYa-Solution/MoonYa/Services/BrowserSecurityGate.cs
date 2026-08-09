using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Linq;
using System.Net.Http;
using System.Net.Security;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Threading;
using System.Threading.Tasks;

namespace MoonYa.Services
{
    public sealed class BrowserAuthorizationChallenge
    {
        public bool Allowed { get; init; }
        public bool Denied { get; init; }
        public string ErrorCode { get; init; } = string.Empty;
        public string ApprovalToken { get; init; } = string.Empty;
        public string ApprovalKind { get; init; } = string.Empty;
        public string Url { get; init; } = string.Empty;
        public string Domain { get; init; } = string.Empty;
        public string Reason { get; init; } = string.Empty;
        public string CertificateFingerprint { get; init; } = string.Empty;
        public string RiskCategory { get; init; } = string.Empty;
        public string Action { get; init; } = string.Empty;
        public long PageVersion { get; init; }
    }

    internal sealed class PendingBrowserApproval
    {
        public string Token { get; init; } = string.Empty;
        public string Kind { get; init; } = string.Empty;
        public string Url { get; init; } = string.Empty;
        public string Domain { get; init; } = string.Empty;
        public string Reason { get; init; } = string.Empty;
        public string Fingerprint { get; init; } = string.Empty;
        public string RiskCategory { get; init; } = string.Empty;
        public string UserContext { get; init; } = string.Empty;
        public string SessionId { get; init; } = string.Empty;
        public string Action { get; init; } = string.Empty;
        public long PageVersion { get; init; }
        public DateTimeOffset ExpiresAt { get; init; }
        public bool Approved { get; set; }
    }

    /// <summary>
    /// Web-driven browser authorization gate. It never creates native UI. The
    /// HTTP layer returns a challenge and the WebView posts a decision before
    /// replaying the original request.
    /// </summary>
    public sealed class BrowserSecurityGate
    {
        private readonly BrowserAutomationConfig _config;
        private readonly ConcurrentDictionary<string, PendingBrowserApproval> _pending = new(StringComparer.Ordinal);
        private readonly HashSet<string> _sessionTrustedDomains = new(StringComparer.OrdinalIgnoreCase);
        private readonly HashSet<string> _sessionTrustedCertificates = new(StringComparer.OrdinalIgnoreCase);
        private readonly object _trustLock = new();

        public BrowserSecurityGate(BrowserAutomationConfig config)
        {
            _config = config ?? throw new ArgumentNullException(nameof(config));
        }

        public async Task<BrowserAuthorizationChallenge> CheckAsync(
            string url,
            string riskCategory,
            string approvalToken,
            string userContext,
            string sessionId,
            long pageVersion,
            string action,
            CancellationToken cancellationToken = default)
        {
            CleanupExpired();
            if (!Uri.TryCreate(url, UriKind.Absolute, out var uri) || string.IsNullOrWhiteSpace(uri.Host))
            {
                return Denied(url, string.Empty, BrowserProtocol.Errors.InvalidRequest, "URL 无效");
            }

            var domain = uri.IdnHost;
            if (_config.BlockedDomains.Any(item => string.Equals(item, domain, StringComparison.OrdinalIgnoreCase)))
            {
                return Denied(url, domain, BrowserProtocol.Errors.ApprovalDenied, "该站点已被阻止");
            }

            if (!IsSiteTrusted(domain, userContext))
            {
                return CreateChallenge("site", url, domain, "首次访问该站点，需要用户授权", string.Empty, string.Empty, userContext, sessionId, pageVersion, action);
            }

            if (string.Equals(uri.Scheme, Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase))
            {
                var certificate = await InspectCertificateAsync(uri, cancellationToken);
                if (certificate.Errors != SslPolicyErrors.None)
                {
                    var certificateKey = domain + "|" + certificate.Fingerprint;
                    lock (_trustLock)
                    {
                        if (!_sessionTrustedCertificates.Contains(certificateKey))
                        {
                            return CreateChallenge(
                                "tls",
                                url,
                                domain,
                                "TLS 证书校验失败: " + certificate.Errors,
                                certificate.Fingerprint,
                                string.Empty,
                                userContext,
                                sessionId,
                                pageVersion,
                                action);
                        }
                    }
                }
            }

            var normalizedRisk = NormalizeRisk(riskCategory);
            if (normalizedRisk != "none")
            {
                if (!string.IsNullOrWhiteSpace(approvalToken)
                    && _pending.TryGetValue(approvalToken, out var approved)
                    && approved.Approved
                    && approved.Kind == "sensitive"
                    && approved.Domain.Equals(domain, StringComparison.OrdinalIgnoreCase)
                    && approved.RiskCategory == normalizedRisk
                    && approved.UserContext == userContext
                    && approved.SessionId == sessionId
                    && approved.PageVersion == pageVersion
                    && approved.Action == action)
                {
                    _pending.TryRemove(approvalToken, out _);
                    return new BrowserAuthorizationChallenge { Allowed = true, Url = url, Domain = domain };
                }
                return CreateChallenge(
                    "sensitive",
                    url,
                    domain,
                    "该操作属于敏感动作，需要单独确认",
                    string.Empty,
                    normalizedRisk,
                    userContext,
                    sessionId,
                    pageVersion,
                    action);
            }

            return new BrowserAuthorizationChallenge { Allowed = true, Url = url, Domain = domain };
        }

        public BrowserAuthorizationChallenge Decide(
            string token,
            string decision,
            string userContext,
            string sessionId,
            long pageVersion,
            string action)
        {
            CleanupExpired();
            if (!_pending.TryGetValue(token, out var pending))
            {
                return Denied(string.Empty, string.Empty, BrowserProtocol.Errors.InvalidRequest, "确认令牌不存在或已过期");
            }
            if (!string.Equals(pending.UserContext, userContext, StringComparison.Ordinal)
                || !string.Equals(pending.SessionId, sessionId, StringComparison.Ordinal)
                || pending.PageVersion != pageVersion
                || !string.Equals(pending.Action, action, StringComparison.Ordinal))
            {
                return Denied(pending.Url, pending.Domain, BrowserProtocol.Errors.ApprovalDenied, "确认令牌与用户、会话、页面版本或动作不匹配");
            }

            if (decision == "deny")
            {
                _pending.TryRemove(token, out _);
                return Denied(pending.Url, pending.Domain, BrowserProtocol.Errors.ApprovalDenied, "用户拒绝操作");
            }
            if (decision != "allow_once" && decision != "allow_always")
            {
                return Denied(pending.Url, pending.Domain, BrowserProtocol.Errors.InvalidRequest, "确认决定无效");
            }

            lock (_trustLock)
            {
                if (pending.Kind == "site")
                {
                    _sessionTrustedDomains.Add(SiteTrustKey(pending.UserContext, pending.Domain));
                    _pending.TryRemove(token, out _);
                }
                else if (pending.Kind == "tls")
                {
                    _sessionTrustedCertificates.Add(pending.Domain + "|" + pending.Fingerprint);
                    _pending.TryRemove(token, out _);
                }
                else if (pending.Kind == "sensitive")
                {
                    pending.Approved = true;
                }
            }

            return new BrowserAuthorizationChallenge
            {
                Allowed = true,
                ApprovalToken = pending.Kind == "sensitive" ? token : string.Empty,
                ApprovalKind = pending.Kind,
                Url = pending.Url,
                Domain = pending.Domain,
            };
        }

        public void ClearSession()
        {
            lock (_trustLock)
            {
                _sessionTrustedDomains.Clear();
                _sessionTrustedCertificates.Clear();
                _pending.Clear();
            }
        }

        private bool IsSiteTrusted(string domain, string userContext)
        {
            lock (_trustLock)
            {
                return _sessionTrustedDomains.Contains(SiteTrustKey(userContext, domain))
                    || _config.TrustedDomains.Any(item => string.Equals(item, domain, StringComparison.OrdinalIgnoreCase));
            }
        }

        private static string SiteTrustKey(string userContext, string domain) => userContext + "\n" + domain;

        private BrowserAuthorizationChallenge CreateChallenge(
            string kind,
            string url,
            string domain,
            string reason,
            string fingerprint,
            string riskCategory,
            string userContext,
            string sessionId,
            long pageVersion,
            string action)
        {
            var token = Convert.ToHexString(RandomNumberGenerator.GetBytes(24)).ToLowerInvariant();
            var pending = new PendingBrowserApproval
            {
                Token = token,
                Kind = kind,
                Url = url,
                Domain = domain,
                Reason = reason,
                Fingerprint = fingerprint,
                RiskCategory = riskCategory,
                UserContext = userContext,
                SessionId = sessionId,
                PageVersion = pageVersion,
                Action = action,
                ExpiresAt = DateTimeOffset.UtcNow.AddMilliseconds(_config.DefaultTimeoutMs * 4L),
            };
            _pending[token] = pending;
            return new BrowserAuthorizationChallenge
            {
                Allowed = false,
                ErrorCode = BrowserProtocol.Errors.ApprovalRequired,
                ApprovalToken = token,
                ApprovalKind = kind,
                Url = url,
                Domain = domain,
                Reason = reason,
                CertificateFingerprint = fingerprint,
                RiskCategory = riskCategory,
                PageVersion = pageVersion,
                Action = action,
            };
        }

        public object[] ListPersistentPermissions()
        {
            lock (_trustLock)
            {
                return _config.TrustedDomains
                    .Distinct(StringComparer.OrdinalIgnoreCase)
                    .OrderBy(domain => domain, StringComparer.OrdinalIgnoreCase)
                    .Select(domain => (object)new { domain, permission = "allow_always" })
                    .ToArray();
            }
        }

        public bool RevokeSessionPermission(string domain, string userContext)
        {
            if (string.IsNullOrWhiteSpace(domain)) return false;
            lock (_trustLock)
            {
                return _sessionTrustedDomains.Remove(SiteTrustKey(userContext, domain));
            }
        }

        private static BrowserAuthorizationChallenge Denied(string url, string domain, string errorCode, string reason) => new()
        {
            Denied = true,
            ErrorCode = errorCode,
            Url = url,
            Domain = domain,
            Reason = reason,
        };

        private async Task<(SslPolicyErrors Errors, string Fingerprint)> InspectCertificateAsync(Uri uri, CancellationToken cancellationToken)
        {
            var errors = SslPolicyErrors.None;
            var fingerprint = string.Empty;
            using var handler = new HttpClientHandler
            {
                AllowAutoRedirect = false,
                ServerCertificateCustomValidationCallback = (_, certificate, _, policyErrors) =>
                {
                    errors = policyErrors;
                    if (certificate != null)
                    {
                        var cert2 = certificate as X509Certificate2 ?? new X509Certificate2(certificate);
                        fingerprint = cert2.GetCertHashString(HashAlgorithmName.SHA256).ToLowerInvariant();
                    }
                    return true;
                },
            };
            using var client = new HttpClient(handler) { Timeout = TimeSpan.FromMilliseconds(_config.ElementTimeoutMs) };
            try
            {
                using var request = new HttpRequestMessage(HttpMethod.Head, uri);
                using var response = await client.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken);
            }
            catch (Exception)
            {
                // Navigation reports transport failures. The gate only classifies certificates it could inspect.
            }
            return (errors, fingerprint);
        }

        private void CleanupExpired()
        {
            var now = DateTimeOffset.UtcNow;
            foreach (var item in _pending)
            {
                if (item.Value.ExpiresAt <= now) _pending.TryRemove(item.Key, out _);
            }
        }

        private static string NormalizeRisk(string riskCategory)
        {
            return riskCategory switch
            {
                "submit_personal_data" => riskCategory,
                "purchase" => riskCategory,
                "change_permissions" => riskCategory,
                "delete_data" => riskCategory,
                _ => "none",
            };
        }
    }
}
