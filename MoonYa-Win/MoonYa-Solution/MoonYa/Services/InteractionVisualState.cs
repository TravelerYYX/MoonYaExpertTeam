using System;

namespace MoonYa.Services
{
    internal enum InteractionVisualOwner
    {
        Off,
        ComputerUse,
        Voice,
        PushToTalk
    }

    /// <summary>
    /// Independent desired channels for edge animation arbitration. No setter
    /// mutates another channel; CU badge visibility is independent of ownership.
    /// </summary>
    internal sealed class InteractionVisualState
    {
        internal bool PttActive { get; set; }
        internal string VoiceMode { get; set; } = "off";
        internal string CuMode { get; set; } = "off";

        internal InteractionVisualOwner Owner => PttActive
            ? InteractionVisualOwner.PushToTalk
            : !string.Equals(VoiceMode, "off", StringComparison.Ordinal)
                ? InteractionVisualOwner.Voice
                : string.Equals(CuMode, "active", StringComparison.Ordinal)
                    ? InteractionVisualOwner.ComputerUse
                    : InteractionVisualOwner.Off;

        internal bool CuBadgeVisible => string.Equals(CuMode, "active", StringComparison.Ordinal);
    }
}
