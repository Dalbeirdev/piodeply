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
}
