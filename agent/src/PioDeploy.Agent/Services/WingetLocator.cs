using System.Text.RegularExpressions;

namespace PioDeploy.Agent.Services;

/// <summary>Resolves the winget executable. The bare <c>winget</c> command is
/// an App Execution Alias that only exists on a signed-in user's PATH — a
/// service running as LocalSystem must launch the real winget.exe from under
/// <c>C:\Program Files\WindowsApps\Microsoft.DesktopAppInstaller_…</c>.</summary>
public static class WingetLocator
{
    public static string Resolve()
    {
        try
        {
            var root = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles),
                "WindowsApps");

            if (Directory.Exists(root))
            {
                var candidates = Directory
                    .GetDirectories(root, "Microsoft.DesktopAppInstaller_*__8wekyb3d8bbwe")
                    .Select(d => Path.Combine(d, "winget.exe"))
                    .Where(File.Exists);

                var exe = PickNewest(candidates);
                if (exe is not null)
                {
                    return exe;
                }
            }
        }
        catch
        {
            // Enumeration can fail without SYSTEM rights — fall back to PATH.
        }

        return "winget"; // interactive sessions, or when resolution fails
    }

    /// <summary>Picks winget.exe from the highest DesktopAppInstaller version
    /// folder. Pure, so it can be unit tested without the real filesystem.</summary>
    public static string? PickNewest(IEnumerable<string> candidatePaths)
    {
        string? bestPath = null;
        Version bestVersion = new(0, 0);

        foreach (var path in candidatePaths)
        {
            var match = Regex.Match(path, @"_(\d+(?:\.\d+){1,3})_");
            var version = match.Success && Version.TryParse(match.Groups[1].Value, out var parsed)
                ? parsed
                : new Version(0, 0);

            if (bestPath is null || version > bestVersion)
            {
                bestPath = path;
                bestVersion = version;
            }
        }

        return bestPath;
    }
}
