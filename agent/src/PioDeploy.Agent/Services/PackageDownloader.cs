using System.Security.Cryptography;

namespace PioDeploy.Agent.Services;

public interface IChecksumVerifier
{
    Task<bool> MatchesSha256Async(string filePath, string expectedSha256, CancellationToken ct);
}

public sealed class ChecksumVerifier : IChecksumVerifier
{
    public async Task<bool> MatchesSha256Async(string filePath, string expectedSha256, CancellationToken ct)
    {
        await using var stream = File.OpenRead(filePath);
        var hash = await SHA256.HashDataAsync(stream, ct);

        return string.Equals(Convert.ToHexString(hash), expectedSha256.Trim(),
            StringComparison.OrdinalIgnoreCase);
    }
}

public interface IPackageDownloader
{
    /// <summary>Downloads an installer into the job's scratch directory and
    /// returns the local path.</summary>
    Task<string> DownloadAsync(long jobId, string url, CancellationToken ct);

    /// <summary>Removes the job's scratch directory (best effort).</summary>
    void Cleanup(long jobId);
}

public sealed class PackageDownloader : IPackageDownloader
{
    private readonly IHttpClientFactory _httpClientFactory;
    private readonly string _root;

    public PackageDownloader(IHttpClientFactory httpClientFactory, string? root = null)
    {
        _httpClientFactory = httpClientFactory;
        _root = root ?? Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "PioDeploy", "downloads");
    }

    public async Task<string> DownloadAsync(long jobId, string url, CancellationToken ct)
    {
        var directory = Path.Combine(_root, jobId.ToString());
        Directory.CreateDirectory(directory);

        var fileName = Path.GetFileName(new Uri(url).LocalPath);
        if (string.IsNullOrWhiteSpace(fileName))
        {
            fileName = "installer.bin";
        }
        var target = Path.Combine(directory, fileName);

        var client = _httpClientFactory.CreateClient("downloads");
        using var response = await client.GetAsync(url, HttpCompletionOption.ResponseHeadersRead, ct);
        response.EnsureSuccessStatusCode();

        await using var source = await response.Content.ReadAsStreamAsync(ct);
        await using var file = File.Create(target);
        await source.CopyToAsync(file, ct);

        return target;
    }

    public void Cleanup(long jobId)
    {
        try
        {
            var directory = Path.Combine(_root, jobId.ToString());
            if (Directory.Exists(directory))
            {
                Directory.Delete(directory, recursive: true);
            }
        }
        catch
        {
            // best effort — leftover scratch files are harmless
        }
    }
}
