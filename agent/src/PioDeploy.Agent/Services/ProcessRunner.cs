using System.Diagnostics;
using System.Text;

namespace PioDeploy.Agent.Services;

public sealed record ProcessResult(int ExitCode, string Output, bool TimedOut);

public interface IProcessRunner
{
    /// <summary>Runs an executable with an argument list (never through a
    /// shell — arguments cannot be injected). Output is merged and capped.</summary>
    Task<ProcessResult> RunAsync(string fileName, IReadOnlyList<string> arguments, TimeSpan timeout, CancellationToken ct);
}

public sealed class ProcessRunner : IProcessRunner
{
    private const int MaxOutputChars = 60_000;

    public async Task<ProcessResult> RunAsync(string fileName, IReadOnlyList<string> arguments, TimeSpan timeout, CancellationToken ct)
    {
        var psi = new ProcessStartInfo
        {
            FileName = fileName,
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
        };
        foreach (var argument in arguments)
        {
            psi.ArgumentList.Add(argument);
        }

        var output = new StringBuilder();
        void Append(string? line)
        {
            if (line is null)
            {
                return;
            }
            lock (output)
            {
                if (output.Length < MaxOutputChars)
                {
                    output.AppendLine(line);
                }
            }
        }

        using var process = new Process { StartInfo = psi };
        process.OutputDataReceived += (_, e) => Append(e.Data);
        process.ErrorDataReceived += (_, e) => Append(e.Data);

        process.Start();
        process.BeginOutputReadLine();
        process.BeginErrorReadLine();

        using var timeoutCts = CancellationTokenSource.CreateLinkedTokenSource(ct);
        timeoutCts.CancelAfter(timeout);

        try
        {
            await process.WaitForExitAsync(timeoutCts.Token);
        }
        catch (OperationCanceledException)
        {
            try
            {
                process.Kill(entireProcessTree: true);
            }
            catch
            {
                // best effort
            }

            return new ProcessResult(-1, output.ToString(), TimedOut: true);
        }

        return new ProcessResult(process.ExitCode, output.ToString(), TimedOut: false);
    }
}
