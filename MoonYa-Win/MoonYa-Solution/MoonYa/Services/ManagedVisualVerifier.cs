using System;
using System.Drawing;
using System.Drawing.Imaging;
using System.IO;
using System.Runtime.InteropServices;

namespace MoonYa.Services
{
    /// <summary>
    /// Managed target-region frame comparison. It deliberately uses Bitmap.LockBits
    /// so publishing x86/x64/ARM64 does not acquire a native OpenCV dependency.
    /// </summary>
    internal static class ManagedVisualVerifier
    {
        internal sealed record Result(bool Changed, double ChangedPixelRatio, double Correlation, string Reason);

        public static Result CompareRegion(string beforeBase64, string afterBase64, Rectangle region)
        {
            try
            {
                using var before = Decode(beforeBase64);
                using var after = Decode(afterBase64);
                if (before.Width != after.Width || before.Height != after.Height)
                {
                    return new Result(true, 1, 0, "frame_dimensions_changed");
                }

                var bounds = Rectangle.Intersect(
                    new Rectangle(0, 0, before.Width, before.Height),
                    region.Width > 0 && region.Height > 0 ? region : new Rectangle(0, 0, before.Width, before.Height));
                if (bounds.Width <= 0 || bounds.Height <= 0)
                {
                    return new Result(false, 0, 1, "empty_target_region");
                }

                var a = ReadArgb(before, bounds);
                var b = ReadArgb(after, bounds);
                long changed = 0;
                double sumA = 0, sumB = 0, sumAA = 0, sumBB = 0, sumAB = 0;
                int pixels = bounds.Width * bounds.Height;
                for (int p = 0; p < pixels; p++)
                {
                    int i = p * 4;
                    int db = Math.Abs(a[i] - b[i]);
                    int dg = Math.Abs(a[i + 1] - b[i + 1]);
                    int dr = Math.Abs(a[i + 2] - b[i + 2]);
                    if (Math.Max(dr, Math.Max(dg, db)) >= 18)
                    {
                        changed++;
                    }
                    double ga = 0.114 * a[i] + 0.587 * a[i + 1] + 0.299 * a[i + 2];
                    double gb = 0.114 * b[i] + 0.587 * b[i + 1] + 0.299 * b[i + 2];
                    sumA += ga;
                    sumB += gb;
                    sumAA += ga * ga;
                    sumBB += gb * gb;
                    sumAB += ga * gb;
                }

                double ratio = pixels == 0 ? 0 : (double)changed / pixels;
                double numerator = pixels * sumAB - sumA * sumB;
                double denominator = Math.Sqrt(
                    Math.Max(0, pixels * sumAA - sumA * sumA)
                    * Math.Max(0, pixels * sumBB - sumB * sumB));
                double correlation = denominator <= 0.0001 ? (ratio == 0 ? 1 : 0) : numerator / denominator;
                bool meaningful = ratio >= 0.02 && correlation <= 0.995;
                return new Result(meaningful, ratio, correlation, meaningful ? "target_region_changed" : "no_meaningful_target_change");
            }
            catch (Exception ex)
            {
                return new Result(false, 0, 1, "comparison_failed:" + ex.GetType().Name);
            }
        }

        private static Bitmap Decode(string base64)
        {
            var bytes = Convert.FromBase64String(base64);
            using var stream = new MemoryStream(bytes, writable: false);
            using var source = new Bitmap(stream);
            return new Bitmap(source);
        }

        private static byte[] ReadArgb(Bitmap source, Rectangle bounds)
        {
            using var argb = source.PixelFormat == PixelFormat.Format32bppArgb
                ? new Bitmap(source)
                : source.Clone(new Rectangle(0, 0, source.Width, source.Height), PixelFormat.Format32bppArgb);
            var data = argb.LockBits(bounds, ImageLockMode.ReadOnly, PixelFormat.Format32bppArgb);
            try
            {
                int rowBytes = bounds.Width * 4;
                var result = new byte[rowBytes * bounds.Height];
                for (int y = 0; y < bounds.Height; y++)
                {
                    Marshal.Copy(IntPtr.Add(data.Scan0, y * data.Stride), result, y * rowBytes, rowBytes);
                }
                return result;
            }
            finally
            {
                argb.UnlockBits(data);
            }
        }
    }
}
