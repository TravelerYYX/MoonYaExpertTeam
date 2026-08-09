// ┌─────────────────────────────────────────────────────────┐
// │  PetController — CefSharp JS 桥接对象                    │
// │  供 user_xinxi.php 调用以控制桌宠显隐                    │
// └─────────────────────────────────────────────────────────┘

using System;
using System.IO;
using System.Text.Json;
using System.Windows;

namespace MoonYa
{
    public class PetController
    {
        private readonly PetWindow _petWindow;

        public PetController(PetWindow petWindow)
        {
            _petWindow = petWindow ?? throw new ArgumentNullException(nameof(petWindow));
        }

        // 由 JS 调用：petController.setEnabled(true / false)
        public void setEnabled(bool enabled)
        {
            try
            {
                Application.Current?.Dispatcher?.Invoke(() =>
                {
                    if (enabled)
                        _petWindow.ShowPet();
                    else
                        _petWindow.HidePet();
                });
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[PetController] setEnabled failed: {ex.Message}");
            }
        }

        // 由 JS 调用：const on = await petController.isEnabled();
        public bool isEnabled()
        {
            try
            {
                if (Application.Current?.Dispatcher?.CheckAccess() ?? true)
                    return _petWindow.IsPetVisible;
                bool result = false;
                Application.Current.Dispatcher.Invoke(() => result = _petWindow.IsPetVisible);
                return result;
            }
            catch
            {
                return false;
            }
        }

        // 由 JS 调用：const on = await petController.isEnabled(); (异步包装)
        public System.Threading.Tasks.Task<bool> isEnabledAsync()
        {
            return System.Threading.Tasks.Task.Run(() => isEnabled());
        }
    }
}
