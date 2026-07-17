using System.Text.RegularExpressions;

namespace PioDeploy.Agent.Services;

/// <summary>Reads `winget upgrade`, which — unlike `winget export` — offers no
/// machine-readable output at all, only a fixed-width table:
///
/// <code>
/// Name              Id                Version      Available    Source
/// ---------------------------------------------------------------------
/// Google Chrome     Google.Chrome     138.0.7615   141.0.7390   winget
/// </code>
///
/// The columns are located from the header rather than by splitting on
/// whitespace, because names and versions contain spaces. Anything we cannot
/// read confidently is dropped: a wrong "update available" sends an operator
/// chasing a version that does not exist, which is worse than saying nothing.
/// </summary>
public static class WingetUpgradeParser
{
    /// <summary>Available versions by package id.</summary>
    public static IReadOnlyDictionary<string, string> Parse(string output)
    {
        var found = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);

        if (string.IsNullOrWhiteSpace(output))
        {
            return found;
        }

        var lines = output.Split('\n').Select(l => l.TrimEnd('\r')).ToList();

        var headerIndex = lines.FindIndex(IsHeader);
        if (headerIndex < 0)
        {
            // Localised output, an error, or a format change. Report nothing
            // rather than guess at column positions.
            return found;
        }

        var header = lines[headerIndex];
        var idAt = header.IndexOf("Id", StringComparison.Ordinal);
        var versionAt = header.IndexOf("Version", StringComparison.Ordinal);
        var availableAt = header.IndexOf("Available", StringComparison.Ordinal);

        // Available is not the last column — Source follows it, and reading to
        // the end of the line would capture "141.0.7390.55  winget".
        var sourceAt = header.IndexOf("Source", StringComparison.Ordinal);
        var availableEnd = sourceAt > availableAt ? sourceAt : int.MaxValue;

        if (idAt < 0 || versionAt <= idAt || availableAt <= versionAt)
        {
            return found;
        }

        foreach (var line in lines.Skip(headerIndex + 1))
        {
            // The dashed rule under the header, and the trailing summary
            // ("N upgrades available.").
            if (line.Length <= availableAt || line.StartsWith("---", StringComparison.Ordinal))
            {
                continue;
            }

            var id = Slice(line, idAt, versionAt);
            var available = Slice(line, availableAt, availableEnd);

            // winget prints "Unknown" for packages it cannot pin down, and the
            // pinned/explicit markers land in this column too.
            if (id.Length == 0 || available.Length == 0 || !LooksLikeAVersion(available))
            {
                continue;
            }

            found[id] = available;
        }

        return found;
    }

    private static bool IsHeader(string line)
        => line.Contains("Id", StringComparison.Ordinal)
           && line.Contains("Version", StringComparison.Ordinal)
           && line.Contains("Available", StringComparison.Ordinal);

    private static string Slice(string line, int from, int to)
    {
        if (from >= line.Length)
        {
            return string.Empty;
        }

        var end = Math.Min(to, line.Length);

        return line[from..end].Trim();
    }

    /// <summary>Guards against "Unknown", "&lt; 1.0", and stray table glyphs
    /// being stored as though they were versions.</summary>
    private static bool LooksLikeAVersion(string candidate)
        => Regex.IsMatch(candidate, @"^\d+(\.\d+)*([\-+][0-9A-Za-z.\-]+)?$");
}
