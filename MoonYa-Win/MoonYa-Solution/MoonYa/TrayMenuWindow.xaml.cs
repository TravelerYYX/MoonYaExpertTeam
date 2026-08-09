using System;
using System.Windows;

namespace MoonYa
{
    public partial class TrayMenuWindow : Window
    {
        private readonly Action? _onShow;
        private readonly Action? _onExit;
        private bool _isHandled;
        private bool _isClosing;

        public TrayMenuWindow(Action? onShow, Action? onExit)
        {
            InitializeComponent();
            _onShow = onShow;
            _onExit = onExit;
        }

        private void ShowBtn_Click(object sender, RoutedEventArgs e)
        {
            if (_isHandled || _isClosing) return;
            _isHandled = true;
            _isClosing = true;
            _onShow?.Invoke();
            Close();
        }

        private void ExitBtn_Click(object sender, RoutedEventArgs e)
        {
            if (_isHandled || _isClosing) return;
            _isHandled = true;
            _isClosing = true;
            _onExit?.Invoke();
            Close();
        }

        private void Window_Deactivated(object? sender, EventArgs e)
        {
            // Only auto-close if user clicked away (not triggered by button-triggered Close)
            if (_isClosing) return;
            _isClosing = true;
            Close();
        }

        /// <summary>Position the menu above the tray icon.</summary>
        public void PositionNearTray(int trayX, int trayY)
        {
            Measure(new System.Windows.Size(double.PositiveInfinity, double.PositiveInfinity));
            var h = DesiredSize.Height;
            var w = DesiredSize.Width;

            var screen = System.Windows.Forms.Screen.FromPoint(
                new System.Drawing.Point(trayX, trayY));
            var wa = screen.WorkingArea;

            double left = trayX - w / 2;
            double top = trayY - h - 8;

            if (left < wa.Left) left = wa.Left + 4;
            if (top < wa.Top) top = wa.Top + 4;
            if (left + w > wa.Right) left = wa.Right - w - 4;
            if (top + h > wa.Bottom) top = wa.Bottom - h - 4;

            var src = PresentationSource.FromVisual(this);
            var dpiX = src?.CompositionTarget?.TransformToDevice.M11 ?? 1.0;
            var dpiY = src?.CompositionTarget?.TransformToDevice.M22 ?? 1.0;

            Left = left / dpiX;
            Top = top / dpiY;
        }
    }
}
