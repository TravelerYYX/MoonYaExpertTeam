using System;
using System.Drawing;

namespace MoonYa.Services
{
    /// <summary>
    /// The only conversion boundary between VLM crop coordinates, screenshot
    /// pixels and physical virtual-screen coordinates.
    /// </summary>
    internal static class DesktopCoordinateService
    {
        internal static Rectangle NormalizedToPhysical(
            double[] box,
            int originX,
            int originY,
            int originalWidth,
            int originalHeight)
        {
            ValidateNormalizedBox(box);
            return new Rectangle(
                originX + (int)Math.Round(box[0] / 1000d * originalWidth),
                originY + (int)Math.Round(box[1] / 1000d * originalHeight),
                Math.Max(1, (int)Math.Round((box[2] - box[0]) / 1000d * originalWidth)),
                Math.Max(1, (int)Math.Round((box[3] - box[1]) / 1000d * originalHeight)));
        }

        internal static Rectangle NormalizedToImage(double[] box, int imageWidth, int imageHeight)
        {
            ValidateNormalizedBox(box);
            return new Rectangle(
                (int)Math.Round(box[0] / 1000d * imageWidth),
                (int)Math.Round(box[1] / 1000d * imageHeight),
                Math.Max(1, (int)Math.Round((box[2] - box[0]) / 1000d * imageWidth)),
                Math.Max(1, (int)Math.Round((box[3] - box[1]) / 1000d * imageHeight)));
        }

        internal static Rectangle PhysicalToImage(
            Rectangle physical,
            int originX,
            int originY,
            double screenshotScale)
        {
            if (screenshotScale <= 0 || double.IsNaN(screenshotScale) || double.IsInfinity(screenshotScale))
                throw new ArgumentOutOfRangeException(nameof(screenshotScale));
            return new Rectangle(
                (int)Math.Round((physical.Left - originX) * screenshotScale),
                (int)Math.Round((physical.Top - originY) * screenshotScale),
                Math.Max(1, (int)Math.Round(physical.Width * screenshotScale)),
                Math.Max(1, (int)Math.Round(physical.Height * screenshotScale)));
        }

        private static void ValidateNormalizedBox(double[] box)
        {
            if (box == null || box.Length != 4
                || Array.Exists(box, value => value < 0 || value > 1000 || double.IsNaN(value) || double.IsInfinity(value))
                || box[2] <= box[0] || box[3] <= box[1])
                throw new ArgumentException("Normalized box must be ordered and bounded to 0..1000.", nameof(box));
        }
    }
}
