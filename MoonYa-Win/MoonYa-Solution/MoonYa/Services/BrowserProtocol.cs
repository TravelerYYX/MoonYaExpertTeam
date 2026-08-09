using System;
using System.Collections.Generic;

namespace MoonYa.Services
{
    /// <summary>
    /// Browser automation wire contract. Route and action literals live here so
    /// callers do not duplicate transport strings or drift from the gateway.
    /// </summary>
    public static class BrowserProtocol
    {
        public const string RoutePrefix = "/browser";
        public const string ExecuteRoute = RoutePrefix + "/execute";
        public const string AuthorizeRoute = RoutePrefix + "/authorize";
        public const string ServiceManifestRoute = RoutePrefix + "/manifest";
        public const string StatusRoute = RoutePrefix + "/status";
        public const string LegacyInspectRoute = RoutePrefix + "/get-elements";
        public const string InitialDocument = "about:blank";
        public const string NavigationReferrerPolicy = "noReferrer";

        public static class Actions
        {
            public const string Start = "start";
            public const string Status = "status";
            public const string Stop = "stop";
            public const string Navigate = "navigate";
            public const string Back = "back";
            public const string Forward = "forward";
            public const string Reload = "reload";
            public const string Inspect = "inspect";
            public const string Screenshot = "screenshot";
            public const string Click = "click";
            public const string Fill = "fill";
            public const string Hover = "hover";
            public const string Press = "press";
            public const string Select = "select";
            public const string Check = "check";
            public const string Uncheck = "uncheck";
            public const string Scroll = "scroll";
            public const string Wait = "wait";
            public const string NewTab = "new_tab";
            public const string ListTabs = "list_tabs";
            public const string SwitchTab = "switch_tab";
            public const string CloseTab = "close_tab";
            public const string ListDownloads = "list_downloads";

            public static readonly ISet<string> Public = new HashSet<string>(StringComparer.Ordinal)
            {
                Start, Status, Stop, Navigate, Back, Forward, Reload, Inspect,
                Screenshot, Click, Fill, Hover, Press, Select, Check, Uncheck,
                Scroll, Wait, NewTab, ListTabs, SwitchTab, CloseTab, ListDownloads,
            };
        }

        public static class Errors
        {
            public const string InvalidRequest = "invalid_request";
            public const string UnknownAction = "unknown_action";
            public const string BrowserNotStarted = "browser_not_started";
            public const string ElementNotFound = "element_not_found";
            public const string StaleElement = "stale_element";
            public const string ApprovalRequired = "approval_required";
            public const string ApprovalDenied = "approval_denied";
            public const string BrowserLaunchFailed = "browser_launch_failed";
            public const string Timeout = "timeout";
            public const string OriginDenied = "origin_denied";
            public const string RouteDenied = "route_denied";
            public const string InternalError = "internal_error";
        }

        public static string LegacyRoute(string action) => RoutePrefix + "/" + action.Replace('_', '-');

        public static bool IsAllowedRoute(string route)
        {
            if (string.Equals(route, ExecuteRoute, StringComparison.Ordinal) ||
                string.Equals(route, AuthorizeRoute, StringComparison.Ordinal) ||
                string.Equals(route, ServiceManifestRoute, StringComparison.Ordinal) ||
                string.Equals(route, LegacyInspectRoute, StringComparison.Ordinal))
            {
                return true;
            }
            foreach (var action in Actions.Public)
            {
                if (string.Equals(route, LegacyRoute(action), StringComparison.Ordinal)) return true;
            }
            return false;
        }
    }
}
