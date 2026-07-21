using Microsoft.Extensions.Logging.Abstractions;
using PioDeploy.Agent.Installers;
using PioDeploy.Agent.Models;
using PioDeploy.Agent.Services;

namespace PioDeploy.Agent.Tests;

/* ---- Hand-rolled fakes ---- */

file sealed class FakeProcessRunner : IProcessRunner
{
    public string? FileName;
    public IReadOnlyList<string>? Arguments;
    public ProcessResult Result = new(0, "ok", false);

    public Task<ProcessResult> RunAsync(string fileName, IReadOnlyList<string> arguments, TimeSpan timeout, CancellationToken ct)
    {
        FileName = fileName;
        Arguments = arguments;
        return Task.FromResult(Result);
    }
}

file sealed class FakeDownloader : IPackageDownloader
{
    public string PathToReturn = "C:\\fake\\installer.msi";
    public bool Downloaded;
    public bool CleanedUp;

    public Task<string> DownloadAsync(long jobId, string url, CancellationToken ct)
    {
        Downloaded = true;
        return Task.FromResult(PathToReturn);
    }

    public void Cleanup(long jobId) => CleanedUp = true;
}

file sealed class FakeChecksum : IChecksumVerifier
{
    public bool Matches = true;

    public Task<bool> MatchesSha256Async(string filePath, string expectedSha256, CancellationToken ct)
        => Task.FromResult(Matches);
}

public class InstallerEngineTests
{
    private static JobPayload WingetJob(string action = "install") => new()
    {
        JobId = 1, Action = action, Package = "7-Zip", InstallerType = "winget", WingetId = "7zip.7zip",
    };

    private static JobPayload MsiJob() => new()
    {
        JobId = 2, Action = "install", Package = "Tool", InstallerType = "msi",
        InstallerUrl = "https://x.test/tool.msi", Sha256 = new string('a', 64), SilentArgs = "/qn ALLUSERS=1",
    };

    [Fact]
    public async Task Engine_Fails_Gracefully_For_Unknown_Type()
    {
        var engine = new InstallerEngine([], NullLogger<InstallerEngine>.Instance);

        var result = await engine.ExecuteAsync(new JobPayload { InstallerType = "floppy" }, CancellationToken.None);

        Assert.False(result.Success);
        Assert.Contains("No installer available", result.FailureReason);
    }

    [Fact]
    public void Winget_Builds_Exact_Silent_Install_Arguments()
    {
        var args = WingetInstaller.BuildArguments("install", "7zip.7zip", null)!;

        Assert.Equal("install", args[0]);
        Assert.Contains("--id", args);
        Assert.Contains("7zip.7zip", args);
        Assert.Contains("--exact", args);
        Assert.Contains("--silent", args);
        Assert.Contains("--accept-package-agreements", args);
        Assert.Contains("--no-upgrade", args); // install = ensure present, never implicit upgrade
        Assert.Contains("--scope", args);      // SYSTEM + per-user default scope = app lands in the
        Assert.Contains("machine", args);      // SYSTEM profile, invisible to every real user (Brave)
    }

    [Fact]
    public void Winget_Rollback_Also_Forces_Machine_Scope()
    {
        var args = WingetInstaller.BuildArguments("rollback", "X.Y", "1.0")!;

        Assert.Contains("--scope", args);
        Assert.Contains("machine", args);
    }

    [Fact]
    public void Winget_Update_Does_Not_Use_No_Upgrade()
    {
        var args = WingetInstaller.BuildArguments("update", "X.Y", null)!;

        Assert.DoesNotContain("--no-upgrade", args);
        // upgrade matches the scope of whatever is installed; forcing machine
        // here could refuse legitimate upgrades.
        Assert.DoesNotContain("--scope", args);
    }

    [Fact]
    public void Winget_Update_Uses_Upgrade_And_Uninstall_Uses_Uninstall()
    {
        Assert.Equal("upgrade", WingetInstaller.BuildArguments("update", "X.Y", null)![0]);
        Assert.Equal("uninstall", WingetInstaller.BuildArguments("uninstall", "X.Y", null)![0]);
        Assert.Null(WingetInstaller.BuildArguments("rollback", "X.Y", null)); // rollback needs a pinned version
        Assert.Contains("--version", WingetInstaller.BuildArguments("rollback", "X.Y", "1.2.3")!);
    }

    [Fact]
    public async Task Winget_Treats_Already_Installed_As_Success()
    {
        var runner = new FakeProcessRunner { Result = new ProcessResult(WingetInstaller.AlreadyInstalled, "found existing", false) };
        var installer = new WingetInstaller(runner);

        var result = await installer.ExecuteAsync(WingetJob(), CancellationToken.None);

        Assert.True(result.Success);
        Assert.Contains("already installed", result.Log);
    }

    [Fact]
    public async Task Winget_Treats_No_Upgrade_Already_Installed_As_Success()
    {
        var runner = new FakeProcessRunner { Result = new ProcessResult(WingetInstaller.AlreadyInstalledNoUpgrade, "A package version is already installed.", false) };
        var installer = new WingetInstaller(runner);

        var result = await installer.ExecuteAsync(WingetJob(), CancellationToken.None);

        Assert.True(result.Success);
    }

    [Fact]
    public async Task Winget_Maps_Other_Exit_Codes_To_Failure()
    {
        var runner = new FakeProcessRunner { Result = new ProcessResult(1, "boom", false) };
        var installer = new WingetInstaller(runner);

        var result = await installer.ExecuteAsync(WingetJob(), CancellationToken.None);

        Assert.False(result.Success);
        Assert.Equal(1, result.ExitCode);
    }

    [Fact]
    public async Task Msi_Downloads_Verifies_And_Runs_Msiexec()
    {
        var runner = new FakeProcessRunner();
        var downloader = new FakeDownloader();
        var checksum = new FakeChecksum();
        var installer = new MsiInstaller(runner, downloader, checksum);

        var result = await installer.ExecuteAsync(MsiJob(), CancellationToken.None);

        Assert.True(result.Success);
        Assert.True(downloader.Downloaded);
        Assert.True(downloader.CleanedUp);
        Assert.Equal("msiexec", runner.FileName);
        Assert.Equal(["/i", downloader.PathToReturn, "/qn", "/norestart", "/qn", "ALLUSERS=1"], runner.Arguments);
    }

    [Fact]
    public async Task Msi_Refuses_To_Execute_On_Checksum_Mismatch()
    {
        var runner = new FakeProcessRunner();
        var installer = new MsiInstaller(runner, new FakeDownloader(), new FakeChecksum { Matches = false });

        var result = await installer.ExecuteAsync(MsiJob(), CancellationToken.None);

        Assert.False(result.Success);
        Assert.Contains("Checksum mismatch", result.FailureReason);
        Assert.Null(runner.FileName); // process never launched
    }

    [Fact]
    public async Task Msi_Refuses_Job_Without_Checksum()
    {
        var job = MsiJob();
        job.Sha256 = null;
        var installer = new MsiInstaller(new FakeProcessRunner(), new FakeDownloader(), new FakeChecksum());

        var result = await installer.ExecuteAsync(job, CancellationToken.None);

        Assert.False(result.Success);
        Assert.Contains("no SHA-256", result.FailureReason);
    }

    [Fact]
    public async Task Msi_Reboot_Required_Is_Success()
    {
        var runner = new FakeProcessRunner { Result = new ProcessResult(3010, "reboot required", false) };
        var installer = new MsiInstaller(runner, new FakeDownloader(), new FakeChecksum());

        var result = await installer.ExecuteAsync(MsiJob(), CancellationToken.None);

        Assert.True(result.Success);
        Assert.Equal(3010, result.ExitCode);
    }

    /* ---- Removal must mean gone: the field failures behind 1.4.7 ---- */

    [Fact]
    public void Winget_Uninstall_Removes_Every_Copy()
    {
        // A machine commonly carries the same app twice (a per-user copy
        // from a pre-1.4.1 agent plus the machine-wide one). Without
        // --all-versions winget refuses the ambiguous removal (0x8A150016)
        // and the job fails on every retry, forever.
        var args = WingetInstaller.BuildArguments("uninstall", "Brave.Brave", null)!;

        Assert.Contains("--all-versions", args);
    }

    [Fact]
    public async Task Winget_Uninstall_Of_An_Absent_Package_Is_Success()
    {
        // "Uninstall" describes an end state. A package that is not there
        // satisfies it — reporting failure turned every already-clean
        // machine into a red row nobody could ever clear.
        var runner = new FakeProcessRunner
        {
            Result = new ProcessResult(WingetInstaller.NoInstalledPackageFound, "No installed package found", false),
        };
        var installer = new WingetInstaller(runner);

        var result = await installer.ExecuteAsync(WingetJob("uninstall"), CancellationToken.None);

        Assert.True(result.Success);
        Assert.Contains("nothing to remove", result.Log);
    }

    [Fact]
    public async Task Winget_Install_Of_An_Absent_Package_Still_Fails()
    {
        // The same code on an INSTALL is a genuine failure (the id matched
        // nothing) — leniency must not leak across actions.
        var runner = new FakeProcessRunner
        {
            Result = new ProcessResult(WingetInstaller.NoInstalledPackageFound, "No package found", false),
        };
        var installer = new WingetInstaller(runner);

        var result = await installer.ExecuteAsync(WingetJob(), CancellationToken.None);

        Assert.False(result.Success);
    }

    [Fact]
    public void Choco_Uninstall_Removes_Every_Copy()
    {
        var args = ChocoInstaller.BuildArguments("uninstall", "brave", null)!;

        Assert.Contains("--all-versions", args);
    }

    [Fact]
    public async Task Choco_Uninstall_Of_An_Absent_Package_Is_Success()
    {
        // Chocolatey has no exit code for "was not installed" — it says so
        // in words and exits 1, so the words are what we read.
        var runner = new FakeProcessRunner
        {
            Result = new ProcessResult(1, "brave is not installed. Cannot uninstall a non-existent package", false),
        };
        var installer = new ChocoInstaller(runner);
        var job = WingetJob("uninstall");
        job.InstallerType = "choco";
        job.ChocoId = "brave";

        var result = await installer.ExecuteAsync(job, CancellationToken.None);

        Assert.True(result.Success);
        Assert.Contains("nothing to remove", result.Log);
    }

    [Fact]
    public async Task Choco_Real_Failures_Still_Fail()
    {
        var runner = new FakeProcessRunner { Result = new ProcessResult(1, "The install of brave was NOT successful", false) };
        var installer = new ChocoInstaller(runner);
        var job = WingetJob("uninstall");
        job.InstallerType = "choco";
        job.ChocoId = "brave";

        var result = await installer.ExecuteAsync(job, CancellationToken.None);

        Assert.False(result.Success);
    }

    [Fact]
    public async Task Exe_Uninstall_Is_Reported_Unsupported()
    {
        var installer = new ExeInstaller(new FakeProcessRunner(), new FakeDownloader(), new FakeChecksum());
        var job = MsiJob();
        job.InstallerType = "exe";
        job.Action = "uninstall";

        var result = await installer.ExecuteAsync(job, CancellationToken.None);

        Assert.False(result.Success);
        Assert.Contains("not supported", result.FailureReason);
    }

    [Fact]
    public async Task Checksum_Verifier_Detects_Real_Hashes()
    {
        var file = Path.GetTempFileName();
        await File.WriteAllTextAsync(file, "piodeploy checksum test");
        try
        {
            var expected = Convert.ToHexString(
                System.Security.Cryptography.SHA256.HashData(await File.ReadAllBytesAsync(file)));
            var verifier = new ChecksumVerifier();

            Assert.True(await verifier.MatchesSha256Async(file, expected.ToLowerInvariant(), CancellationToken.None));
            Assert.False(await verifier.MatchesSha256Async(file, new string('0', 64), CancellationToken.None));
        }
        finally
        {
            File.Delete(file);
        }
    }

    [Fact]
    public async Task Zip_Extracts_Verified_Archive_To_Apps_Dir()
    {
        var scratch = Path.Combine(Path.GetTempPath(), "piodeploy-zip-test-" + Guid.NewGuid());
        Directory.CreateDirectory(scratch);
        try
        {
            // Build a real zip
            var payloadDir = Path.Combine(scratch, "payload");
            Directory.CreateDirectory(payloadDir);
            await File.WriteAllTextAsync(Path.Combine(payloadDir, "app.txt"), "portable app");
            var zipPath = Path.Combine(scratch, "app.zip");
            System.IO.Compression.ZipFile.CreateFromDirectory(payloadDir, zipPath);

            var appsRoot = Path.Combine(scratch, "apps");
            var installer = new ZipInstaller(
                new FakeProcessRunner(),
                new FakeDownloader { PathToReturn = zipPath },
                new FakeChecksum(),
                appsRoot);

            var job = MsiJob();
            job.InstallerType = "zip";
            job.Package = "Portable Tool";

            var result = await installer.ExecuteAsync(job, CancellationToken.None);

            Assert.True(result.Success);
            Assert.True(File.Exists(Path.Combine(appsRoot, "Portable Tool", "app.txt")));
        }
        finally
        {
            Directory.Delete(scratch, recursive: true);
        }
    }
}
