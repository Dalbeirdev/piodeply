using PioDeploy.Agent.Models;

namespace PioDeploy.Agent.Services;

/// <summary>Finds what a job's package reports as installed in a freshly
/// collected inventory. Only package-manager packages carry an identifier
/// that matches the catalogue exactly; a raw MSI or EXE is listed under its
/// display name, which we cannot map back to a package with confidence, so
/// its version stays unknown rather than guessed.</summary>
public static class InstalledVersionResolver
{
    public static string? Resolve(IReadOnlyList<SoftwareEntry> software, JobPayload job)
    {
        var (id, source) = job.WingetId is not null
            ? (job.WingetId, "winget")
            : (job.ChocoId, "choco");

        if (string.IsNullOrWhiteSpace(id))
        {
            return null;
        }

        var match = software.FirstOrDefault(entry =>
            string.Equals(entry.Source, source, StringComparison.OrdinalIgnoreCase) &&
            string.Equals(entry.Name, id, StringComparison.OrdinalIgnoreCase));

        // An uninstall leaves no row: absent is a real answer, not a failure.
        return match?.Version;
    }
}
