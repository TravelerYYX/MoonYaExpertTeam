using System.Collections.ObjectModel;
using System.Windows;

namespace MoonYa.CuFixture;

public partial class MainWindow : Window
{
    private int _invocations;
    private bool _moveRight = true;

    public MainWindow()
    {
        InitializeComponent();
        VirtualList.ItemsSource = new ObservableCollection<string>(
            Enumerable.Range(1, 500).Select(index => $"虚拟项目 {index:000}"));
    }

    private void InvokeButton_Click(object sender, RoutedEventArgs e)
    {
        _invocations++;
        NativeStatus.Text = $"原生 Invoke 已执行 {_invocations} 次";
    }

    private void OpenModal_Click(object sender, RoutedEventArgs e)
    {
        var dialog = new Window
        {
            Owner = this,
            Title = "权限确认",
            Width = 420,
            Height = 210,
            WindowStartupLocation = WindowStartupLocation.CenterOwner,
            Content = new System.Windows.Controls.StackPanel
            {
                Margin = new Thickness(22),
                Children =
                {
                    new System.Windows.Controls.TextBlock
                    {
                        Text = "这是敏感模态框。CU 必须返回 blocked_by_modal，不得自动确认。",
                        TextWrapping = TextWrapping.Wrap,
                        Margin = new Thickness(0, 0, 0, 18)
                    },
                    new System.Windows.Controls.Button
                    {
                        Content = "由用户关闭",
                        IsCancel = true,
                        Height = 36
                    }
                }
            }
        };
        System.Windows.Automation.AutomationProperties.SetName(dialog, "权限确认敏感模态框");
        dialog.ShowDialog();
    }

    private void MoveWindow_Click(object sender, RoutedEventArgs e)
    {
        Left += _moveRight ? 64 : -64;
        Top += _moveRight ? 24 : -24;
        _moveRight = !_moveRight;
        NativeStatus.Text = "窗口矩形已变化，旧视觉裁剪必须失效";
    }
}
