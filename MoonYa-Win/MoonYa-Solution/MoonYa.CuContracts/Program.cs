using System.Drawing;
using System.Drawing.Imaging;
using MoonYa.Services;

static void Assert(bool condition, string message)
{
    if (!condition) throw new InvalidOperationException(message);
}

// Negative virtual-screen origin + scaled crop. The round-trip budget is the
// release gate from CU.txt: no more than two physical pixels.
var normalized = new[] { 125d, 200d, 625d, 800d };
var physical = DesktopCoordinateService.NormalizedToPhysical(normalized, -1920, -120, 2560, 1440);
var image = DesktopCoordinateService.PhysicalToImage(physical, -1920, -120, 0.625);
var expectedImage = DesktopCoordinateService.NormalizedToImage(normalized, 1600, 900);
Assert(Math.Abs(image.Left - expectedImage.Left) <= 2, "normalized x mapping exceeds 2 px");
Assert(Math.Abs(image.Top - expectedImage.Top) <= 2, "normalized y mapping exceeds 2 px");
Assert(Math.Abs(image.Right - expectedImage.Right) <= 2, "normalized right mapping exceeds 2 px");
Assert(Math.Abs(image.Bottom - expectedImage.Bottom) <= 2, "normalized bottom mapping exceeds 2 px");

var before = CreateFrame(Color.White, Rectangle.Empty, Color.White);
var identical = CreateFrame(Color.White, Rectangle.Empty, Color.White);
var changed = CreateFrame(Color.White, new Rectangle(20, 20, 40, 40), Color.RoyalBlue);
var tiny = CreateFrame(Color.White, new Rectangle(20, 20, 1, 1), Color.Black);
var roi = new Rectangle(0, 0, 100, 100);
Assert(!ManagedVisualVerifier.CompareRegion(before, identical, roi).Changed, "identical frame reported changed");
Assert(ManagedVisualVerifier.CompareRegion(before, changed, roi).Changed, "meaningful target change not detected");
Assert(!ManagedVisualVerifier.CompareRegion(before, tiny, roi).Changed, "one-pixel animation/noise counted as success");

var visualState = new InteractionVisualState { CuMode = "active" };
Assert(visualState.Owner == InteractionVisualOwner.ComputerUse && visualState.CuBadgeVisible,
    "CU must own the idle edge and show its badge");
visualState.VoiceMode = "listening";
Assert(visualState.Owner == InteractionVisualOwner.Voice && visualState.CuBadgeVisible,
    "voice must temporarily own the edge without clearing the CU badge");
visualState.PttActive = true;
Assert(visualState.Owner == InteractionVisualOwner.PushToTalk && visualState.CuBadgeVisible,
    "PTT must outrank voice while preserving the CU channel");
visualState.PttActive = false;
Assert(visualState.Owner == InteractionVisualOwner.Voice,
    "ending PTT must resume voice");
visualState.VoiceMode = "off";
Assert(visualState.Owner == InteractionVisualOwner.ComputerUse,
    "ending voice must resume CU");
visualState.CuMode = "off";
Assert(visualState.Owner == InteractionVisualOwner.Off && !visualState.CuBadgeVisible,
    "leaving CU with no other desired channel must turn visuals off");

Console.WriteLine("CU coordinate/visual contracts: PASS");

static string CreateFrame(Color background, Rectangle change, Color changeColor)
{
    using var bitmap = new Bitmap(100, 100, PixelFormat.Format32bppArgb);
    using (var graphics = Graphics.FromImage(bitmap))
    {
        graphics.Clear(background);
        if (!change.IsEmpty)
        {
            using var brush = new SolidBrush(changeColor);
            graphics.FillRectangle(brush, change);
        }
    }
    using var stream = new MemoryStream();
    bitmap.Save(stream, ImageFormat.Png);
    return Convert.ToBase64String(stream.ToArray());
}
