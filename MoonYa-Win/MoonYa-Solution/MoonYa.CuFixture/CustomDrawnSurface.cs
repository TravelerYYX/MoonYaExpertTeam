using System.Globalization;
using System.Windows;
using System.Windows.Input;
using System.Windows.Media;

namespace MoonYa.CuFixture;

public sealed class CustomDrawnSurface : FrameworkElement
{
    private bool _activated;

    protected override void OnRender(DrawingContext drawingContext)
    {
        drawingContext.DrawRoundedRectangle(
            new SolidColorBrush(Color.FromRgb(241, 245, 252)),
            new Pen(new SolidColorBrush(Color.FromRgb(153, 168, 194)), 1),
            new Rect(0, 0, ActualWidth, ActualHeight), 12, 12);
        var button = new Rect(36, 62, Math.Max(120, ActualWidth - 72), 62);
        drawingContext.DrawRoundedRectangle(
            new SolidColorBrush(_activated ? Color.FromRgb(56, 178, 117) : Color.FromRgb(92, 103, 232)),
            null, button, 12, 12);
        var text = new FormattedText(
            _activated ? "视觉目标已激活" : "点击视觉目标",
            CultureInfo.GetCultureInfo("zh-CN"),
            FlowDirection.LeftToRight,
            new Typeface("Microsoft YaHei UI"), 16, Brushes.White,
            VisualTreeHelper.GetDpi(this).PixelsPerDip);
        drawingContext.DrawText(text, new Point(
            button.Left + (button.Width - text.Width) / 2,
            button.Top + (button.Height - text.Height) / 2));
    }

    protected override void OnMouseLeftButtonUp(MouseButtonEventArgs e)
    {
        var point = e.GetPosition(this);
        var target = new Rect(36, 62, Math.Max(120, ActualWidth - 72), 62);
        if (target.Contains(point))
        {
            _activated = !_activated;
            InvalidateVisual();
        }
        base.OnMouseLeftButtonUp(e);
    }
}
