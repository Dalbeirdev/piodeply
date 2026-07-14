using System.Collections.Concurrent;
using Microsoft.Extensions.Logging;

namespace PioDeploy.Agent.Services;

/// <summary>Minimal rolling file logger (one file per day, size-capped) so
/// the agent keeps local logs without external logging dependencies.</summary>
public sealed class RollingFileLoggerProvider : ILoggerProvider
{
    private readonly string _directory;
    private readonly long _maxBytes;
    private readonly ConcurrentDictionary<string, RollingFileLogger> _loggers = new();
    private readonly object _writeLock = new();

    public RollingFileLoggerProvider(string? directory = null, long maxBytes = 5 * 1024 * 1024)
    {
        _directory = directory ?? Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "PioDeploy", "logs");
        _maxBytes = maxBytes;
        Directory.CreateDirectory(_directory);
    }

    public ILogger CreateLogger(string categoryName)
        => _loggers.GetOrAdd(categoryName, name => new RollingFileLogger(name, WriteLine));

    private void WriteLine(string line)
    {
        lock (_writeLock)
        {
            var path = Path.Combine(_directory, $"agent-{DateTime.UtcNow:yyyyMMdd}.log");
            if (File.Exists(path) && new FileInfo(path).Length > _maxBytes)
            {
                return; // size cap reached for today — drop rather than grow unbounded
            }
            File.AppendAllText(path, line + Environment.NewLine);
        }
    }

    public void Dispose()
    {
    }

    private sealed class RollingFileLogger : ILogger
    {
        private readonly string _category;
        private readonly Action<string> _write;

        public RollingFileLogger(string category, Action<string> write)
        {
            _category = category;
            _write = write;
        }

        public IDisposable? BeginScope<TState>(TState state) where TState : notnull => null;

        public bool IsEnabled(LogLevel logLevel) => logLevel >= LogLevel.Information;

        public void Log<TState>(LogLevel logLevel, EventId eventId, TState state,
            Exception? exception, Func<TState, Exception?, string> formatter)
        {
            if (!IsEnabled(logLevel))
            {
                return;
            }

            var line = $"{DateTime.UtcNow:O} [{logLevel}] {_category}: {formatter(state, exception)}";
            if (exception is not null)
            {
                line += $" | {exception.GetType().Name}: {exception.Message}";
            }
            _write(line);
        }
    }
}
