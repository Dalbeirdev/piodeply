namespace PioDeploy.Agent.Services;

/// <summary>Decides whether the agent should replace itself with the version
/// the server is advertising. Pure, so the decision is unit-tested without
/// touching the filesystem or the service.</summary>
public static class SelfUpdatePlan
{
    /// <summary>True only when the server names a strictly-newer version and a
    /// bundle to get it from. Equal or older is left alone, and a malformed or
    /// missing version never triggers an update.</summary>
    public static bool ShouldUpdate(string running, string? latest, string? bundleUrl)
    {
        if (string.IsNullOrWhiteSpace(latest) || string.IsNullOrWhiteSpace(bundleUrl))
        {
            return false;
        }

        if (!Version.TryParse(running, out var current) || !Version.TryParse(latest, out var target))
        {
            return false;
        }

        return target > current;
    }

    /// <summary>A reinstall is an operator's explicit "replace this install,
    /// whatever version it claims to be" — the remote fix for a broken agent
    /// that still checks in. No version comparison: same-version is the
    /// normal case. Only a missing bundle stops it.</summary>
    public static bool ShouldReinstall(bool requested, string? bundleUrl)
    {
        return requested && !string.IsNullOrWhiteSpace(bundleUrl);
    }
}
