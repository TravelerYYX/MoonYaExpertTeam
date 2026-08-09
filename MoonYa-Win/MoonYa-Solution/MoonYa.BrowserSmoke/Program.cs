using System.Text;
using System.Text.Json;
using System.Net;
using System.Net.Http.Json;
using System.Net.Sockets;
using MoonYa.Services;

static void Require(bool condition, string message)
{
    if (!condition) throw new InvalidOperationException(message);
}

static string DataPage(string html) =>
    "data:text/html;base64," + Convert.ToBase64String(Encoding.UTF8.GetBytes(html));

static (string ElementId, string Selector) FindElement(string elementsJson, string selector)
{
    using var document = JsonDocument.Parse(elementsJson);
    var observedSelectors = new List<string>();
    foreach (var item in document.RootElement.GetProperty("elements").EnumerateArray())
    {
        observedSelectors.Add(item.GetProperty("css_selector").GetString() ?? "<empty>");
        if (string.Equals(item.GetProperty("css_selector").GetString(), selector, StringComparison.Ordinal))
        {
            return (
                item.GetProperty("element_id").GetString() ?? throw new InvalidOperationException("element_id missing"),
                selector
            );
        }
    }
    throw new InvalidOperationException($"semantic element not found: {selector}; observed: {string.Join(", ", observedSelectors)}");
}

var configPath = args.Length > 0
    ? Path.GetFullPath(args[0])
    : Path.GetFullPath(Path.Combine(AppContext.BaseDirectory, "..", "..", "..", "..", "MoonYa", "launcher_config.json"));
using var configDocument = JsonDocument.Parse(File.ReadAllText(configPath));
var browserConfig = JsonSerializer.Deserialize<BrowserAutomationConfig>(
    configDocument.RootElement.GetProperty("browser_automation").GetRawText(),
    new JsonSerializerOptions { PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower })
    ?? throw new InvalidOperationException("browser_automation configuration could not be loaded");

var outputDirectory = Path.Combine(Path.GetTempPath(), "moonya-browser-smoke", Guid.NewGuid().ToString("N"));
Directory.CreateDirectory(outputDirectory);
browserConfig.Headless = true;
browserConfig.AutoDownloadChromium = false;
browserConfig.DownloadDirectory = outputDirectory;
browserConfig.DiagnosticsFile = Path.Combine(outputDirectory, "diagnostics.log");

var gate = new BrowserSecurityGate(browserConfig);
var siteChallenge = await gate.CheckAsync(
    "http://example.invalid/form", "none", "", "user-one", "session-one", 4, BrowserProtocol.Actions.Navigate);
Require(siteChallenge.ErrorCode == BrowserProtocol.Errors.ApprovalRequired && siteChallenge.ApprovalKind == "site", "first-site challenge missing");
var mismatchedDecision = gate.Decide(
    siteChallenge.ApprovalToken, "allow_once", "user-two", "session-one", 4, BrowserProtocol.Actions.Navigate);
Require(mismatchedDecision.Denied, "approval token accepted for a different user");
var siteDecision = gate.Decide(
    siteChallenge.ApprovalToken, "allow_once", "user-one", "session-one", 4, BrowserProtocol.Actions.Navigate);
Require(siteDecision.Allowed, "site approval was not accepted");
var sensitiveChallenge = await gate.CheckAsync(
    "http://example.invalid/form", "delete_data", "", "user-one", "session-one", 5, BrowserProtocol.Actions.Click);
Require(sensitiveChallenge.ApprovalKind == "sensitive", "sensitive action challenge missing");
var sensitiveDecision = gate.Decide(
    sensitiveChallenge.ApprovalToken, "allow_once", "user-one", "session-one", 5, BrowserProtocol.Actions.Click);
Require(sensitiveDecision.Allowed && sensitiveDecision.ApprovalToken != "", "sensitive approval token missing");
var wrongPageReplay = await gate.CheckAsync(
    "http://example.invalid/form", "delete_data", sensitiveDecision.ApprovalToken,
    "user-one", "session-one", 6, BrowserProtocol.Actions.Click);
Require(!wrongPageReplay.Allowed, "sensitive approval token accepted for a different page version");
var exactReplay = await gate.CheckAsync(
    "http://example.invalid/form", "delete_data", sensitiveDecision.ApprovalToken,
    "user-one", "session-one", 5, BrowserProtocol.Actions.Click);
Require(exactReplay.Allowed, "bound sensitive approval token was rejected");
gate.ClearSession();
var clearedChallenge = await gate.CheckAsync(
    "http://example.invalid/form", "none", "", "user-one", "session-two", 0, BrowserProtocol.Actions.Navigate);
Require(clearedChallenge.ApprovalKind == "site", "site session permission survived session cleanup");

var firstPage = DataPage("""
<!doctype html><html><head><meta charset="utf-8"><title>Browser smoke one</title></head>
<body style="min-height:2200px">
  <label for="name">Name</label><input id="name" autocomplete="name">
  <select id="choice" aria-label="Choice"><option value="a">A</option><option value="b">B</option></select>
  <label><input id="agree" type="checkbox">Agree</label>
  <button id="commit" aria-label="Commit" onclick="document.querySelector('#status').textContent=document.querySelector('#name').value+'|'+document.querySelector('#choice').value+'|'+document.querySelector('#agree').checked">Commit</button>
  <button id="hover" onmouseenter="document.querySelector('#status').textContent='hovered'">Hover</button>
  <a id="download" download="artifact.txt" href="data:text/plain,download-ok">Download</a>
  <div id="editable" contenteditable="true" aria-label="Editable"></div>
  <div id="generic" role="button" tabindex="0" aria-label="Generic action" onclick="status.textContent='generic'">Generic</div>
  <dialog open aria-label="Dialog"><button id="dialog-action">Dialog action</button></dialog>
  <div id="shadow-host"></div>
  <iframe id="frame" title="Frame" srcdoc="<button id='frame-action' aria-label='Frame action'>Frame action</button>"></iframe>
  <div id="status" aria-live="polite">ready</div>
  <script>
    const root = document.querySelector('#shadow-host').attachShadow({mode:'open'});
    const button = document.createElement('button');
    button.id = 'shadow-action'; button.setAttribute('aria-label','Shadow action'); button.textContent='Shadow action';
    button.onclick = () => document.querySelector('#status').textContent = 'shadow'; root.append(button);
  </script>
</body></html>
""");
var secondPage = DataPage("<!doctype html><title>Browser smoke two</title><p>second-page</p>");

using var browser = new BrowserAutomationService(browserConfig);
BrowserApiServer? apiServer = null;
try
{
    await browser.StartAsync(firstPage);
    var loopbackAddress = IPAddress.Parse(browserConfig.LoopbackHost);
    var portProbe = new TcpListener(loopbackAddress, 0);
    portProbe.Start();
    var apiPort = ((IPEndPoint)portProbe.LocalEndpoint).Port;
    portProbe.Stop();
    apiServer = new BrowserApiServer(browser, new BrowserSecurityGate(browserConfig), browserConfig.LoopbackHost, apiPort);
    await apiServer.StartAsync();
    Require(apiServer.IsRunning, "browser API server did not start");
    using (var client = new HttpClient { BaseAddress = new UriBuilder(Uri.UriSchemeHttp, browserConfig.LoopbackHost, apiPort).Uri })
    {
        var manifest = await client.GetFromJsonAsync<JsonElement>(BrowserProtocol.ServiceManifestRoute);
        Require(manifest.GetProperty("success").GetBoolean(), "browser manifest route failed");
        var statusResponse = await client.PostAsJsonAsync(BrowserProtocol.ExecuteRoute, new { action = BrowserProtocol.Actions.Status });
        var statusResult = await statusResponse.Content.ReadFromJsonAsync<JsonElement>();
        Require(statusResult.GetProperty("success").GetBoolean() && statusResult.TryGetProperty("browser", out _), "unified status route failed");
        var legacyInspection = await client.GetFromJsonAsync<JsonElement>(BrowserProtocol.LegacyInspectRoute);
        Require(legacyInspection.GetProperty("success").GetBoolean(), "legacy inspect compatibility route failed");
        var unknownResponse = await client.PostAsJsonAsync(BrowserProtocol.ExecuteRoute, new { action = "unknown_for_contract_test" });
        var unknownResult = await unknownResponse.Content.ReadFromJsonAsync<JsonElement>();
        Require(unknownResult.GetProperty("error_code").GetString() == BrowserProtocol.Errors.UnknownAction, "unknown action error code drifted");
        var internalRouteResponse = await client.PostAsJsonAsync(BrowserProtocol.RoutePrefix + "/evaluate", new { js_code = "document.title" });
        var internalRouteResult = await internalRouteResponse.Content.ReadFromJsonAsync<JsonElement>();
        Require(!internalRouteResult.GetProperty("success").GetBoolean(), "internal JavaScript evaluation route is publicly callable");
    }
    var elements = await browser.GetElementsAsync();
    var name = FindElement(elements, "#name");
    var choice = FindElement(elements, "#choice");
    var agree = FindElement(elements, "#agree");
    var commit = FindElement(elements, "#commit");
    var hover = FindElement(elements, "#hover");
    var download = FindElement(elements, "#download[href*=\"text\"]");
    _ = FindElement(elements, "#dialog-action");
    _ = FindElement(elements, "#frame-action");

    var shadow = FindElement(elements, "#shadow-action");
    await browser.FillAsync(browser.ResolveElementTarget(name.ElementId, null), "hello");
    await browser.PressAsync(browser.ResolveElementTarget(name.ElementId, null), "End");
    await browser.SelectAsync(browser.ResolveElementTarget(choice.ElementId, null), ["b"]);
    await browser.SetCheckedAsync(browser.ResolveElementTarget(agree.ElementId, null), true);
    await browser.ClickAsync(browser.ResolveElementTarget(commit.ElementId, null));
    await browser.WaitForConditionAsync("text", 3000, null, "hello|b|true", null, null);
    Require(await browser.GetTextAsync("#status") == "hello|b|true", "form result was not verified");

    await browser.HoverAsync(browser.ResolveElementTarget(hover.ElementId, null));
    await browser.WaitForConditionAsync("text", 3000, null, "hovered", null, null);
    await browser.ClickAsync(browser.ResolveElementTarget(shadow.ElementId, null));
    Require(await browser.GetTextAsync("#status") == "shadow", "shadow DOM action failed");
    await browser.SetCheckedAsync(browser.ResolveElementTarget(agree.ElementId, null), false);
    await browser.ScrollAsync("down", browser.DefaultScrollAmount);
    await browser.WaitForConditionAsync("dom_stable", 3000, null, null, null, null);

    var screenshot = Convert.FromBase64String(await browser.ScreenshotBase64Async());
    Require(screenshot.Length > 8 && screenshot[0] == 0x89 && screenshot[1] == 0x50, "screenshot is not a PNG image");

    await browser.ClickAsync(browser.ResolveElementTarget(download.ElementId, null));
    await browser.WaitAsync(1000);
    var downloads = await browser.ListDownloadsAsync();
    Require(downloads.Length > 0, "download artifact was not reported");

    var originalTab = browser.CurrentTabId;
    var newTab = await browser.NewTabAsync(secondPage);
    Require((await browser.ListTabsAsync()).Length >= 2, "new tab was not listed");
    await browser.SwitchTabAsync(originalTab);
    await browser.CloseTabAsync(newTab);
    Require(browser.CurrentTabId == originalTab && browser.CurrentUrl == firstPage, "closing a background tab changed the active tab");

    var oldElementId = name.ElementId;
    await browser.NavigateAsync(secondPage);
    await browser.GetElementsAsync();
    try
    {
        _ = browser.ResolveElementTarget(oldElementId, null);
        throw new InvalidOperationException("stale element was accepted");
    }
    catch (InvalidOperationException error) when (error.Message.Contains("stale_element", StringComparison.Ordinal))
    {
    }

    await browser.BackAsync();
    Require(browser.CurrentUrl == firstPage, "back navigation failed");
    await browser.ForwardAsync();
    Require(browser.CurrentUrl == secondPage, "forward navigation failed");
    await browser.ReloadAsync();
    Require((await browser.GetStatusAsync()) is not null, "status was not returned");

    Console.WriteLine("browser smoke test OK");
}
finally
{
    if (apiServer != null) await apiServer.StopAsync();
    await browser.StopAsync();
}
