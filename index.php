<?php

declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
$config = is_file($configFile)
    ? require $configFile
    : require __DIR__ . '/config.example.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function githubRequest(string $url, string $token = ''): array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: ChilSoft-Website',
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('GitHub could not be reached.');
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('GitHub returned HTTP ' . $status . '.');
    }

    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
}

function loadGithubRepos(array $config): array
{
    $username = (string) ($config['github_username'] ?? '');
    $token = trim((string) ($config['github_token'] ?? ''));
    $cacheSeconds = max(60, (int) ($config['github_cache_seconds'] ?? 900));
    $cacheDir = __DIR__ . '/cache';
    $cacheFile = $cacheDir . '/github-repos.json';

    if (is_file($cacheFile) && filemtime($cacheFile) >= time() - $cacheSeconds) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    // Authenticated requests use /user/repos so private repositories owned by
    // the token holder can be included. Public-only mode uses /users/:name/repos.
    $url = $token !== ''
        ? 'https://api.github.com/user/repos?per_page=100&affiliation=owner&sort=updated'
        : 'https://api.github.com/users/' . rawurlencode($username) . '/repos?per_page=100&sort=updated';

    try {
        $repos = githubRequest($url, $token);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }
        $temporary = $cacheFile . '.tmp';
        file_put_contents($temporary, json_encode($repos, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($temporary, $cacheFile);
        return $repos;
    } catch (Throwable $error) {
        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }
        return [];
    }
}

function normalizeRepo(array $repo): array
{
    return [
        'name' => (string) ($repo['name'] ?? 'Unnamed repository'),
        'link' => (string) ($repo['html_url'] ?? '#'),
        'description' => trim((string) ($repo['description'] ?? '')) ?: 'No description provided.',
        'language' => trim((string) ($repo['language'] ?? '')),
        'stars' => (int) ($repo['stargazers_count'] ?? 0),
        'updated' => isset($repo['updated_at']) ? date('n/j/Y', strtotime((string) $repo['updated_at'])) : '',
        'archived' => (bool) ($repo['archived'] ?? false),
        'private' => (bool) ($repo['private'] ?? false),
        'custom' => false,
    ];
}

$projects = [];
foreach (($config['custom_projects'] ?? []) as $project) {
    if (!is_array($project)) {
        continue;
    }
    $projects[] = [
        'name' => (string) ($project['name'] ?? 'Unnamed project'),
        'link' => (string) ($project['link'] ?? '#'),
        'description' => (string) ($project['description'] ?? 'No description provided.'),
        'language' => '',
        'stars' => 0,
        'updated' => '',
        'archived' => (bool) ($project['archived'] ?? false),
        'private' => false,
        'custom' => true,
    ];
}

foreach (loadGithubRepos($config) as $repo) {
    if (is_array($repo) && !($repo['fork'] ?? false)) {
        $projects[] = normalizeRepo($repo);
    }
}

$activeProjects = array_values(array_filter($projects, static fn(array $project): bool => !$project['archived']));
$archivedProjects = array_values(array_filter($projects, static fn(array $project): bool => $project['archived']));
$showArchived = (bool) ($config['show_archived'] ?? true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ChilSoft</title>
  <link rel="stylesheet" href="assets/css/main-styles.css">
</head>
<body>
  <canvas id="matrix"></canvas>

  <div class="logo-right">
    <img src="assets/img/logo.png" alt="ChilSoft Logo" class="full-logo">
  </div>

  <main>
    <section class="welcome-box">
      <h2>Welcome to ChilSoft</h2>
      <p>Home to a growing collection of code, tools, tweaks, and digital duct tape. Some of it I built for my home lab. Some of it I built because I was bored. Some of it may have just... happened. It’s mostly open-source, mostly mobile-friendly, and definitely held together by hope and caffeine.</p>
      <p>If you find something useful, great! If not, consider it an art installation. Either way, <a href="https://paypal.me/chilsoft?country.x=US&amp;locale.x=en_US" target="_blank" rel="noopener noreferrer" class="donate-link" title="Your coffee helps keep this site 12% less broken.">buy me a coffee… or a Lamborghini</a>. I’ll take it in red. Or matte black. Or a vintage ThinkPad shell filled with jellybeans. I’m flexible.</p>
      <p id="quote-box" class="quote-line"></p>
      <p>Site uptime not guaranteed. Neither is sanity. But hey, at least the matrix background is cool.</p>
      <p><span class="emoji">☕</span><span class="emoji">🚗</span><span class="emoji">💙</span></p>
    </section>

    <section class="card-box" id="active">
      <div class="active-header">
        <h2 title="Somehow still working. Don’t ask how.">Active Projects</h2>
        <p class="active-subtitle" title="Definitely not abandoned. Probably.">Actively maintained. Or at least actively ignored with good intentions.</p>
      </div>
      <div class="repo-list" id="active-list">
        <?php foreach ($activeProjects as $project): ?>
          <div class="repo-card">
            <h3><a href="<?= h($project['link']) ?>" target="_blank" rel="noopener noreferrer"><?= h($project['name']) ?></a></h3>
            <p><?= nl2br(h($project['description'])) ?></p>
            <div class="repo-meta">
              <?php if ($project['custom']): ?><span class="badge">Custom Project (Non-GitHub)</span><?php endif; ?>
              <?php if ($project['private']): ?><span class="badge">Private Repository</span><?php endif; ?>
              <?php if ($project['language'] !== ''): ?><span class="badge"><?= h($project['language']) ?></span><?php endif; ?>
              <?php if ($project['stars'] > 0): ?><span class="badge">⭐ <?= $project['stars'] ?></span><?php endif; ?>
              <?php if ($project['updated'] !== ''): ?><span class="badge">Updated: <?= h($project['updated']) ?></span><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($activeProjects === []): ?><p>No active projects are currently listed.</p><?php endif; ?>
      </div>
    </section>

    <?php if ($showArchived && $archivedProjects !== []): ?>
      <section class="card-box" id="archived">
        <div class="archive-header collapsible" id="toggle-archive">
          <div class="archive-title-block">
            <h2 title="Abandon all hope, ye who fork here."><span id="archive-arrow">▶</span> Archived Projects</h2>
            <p class="archive-subtitle" title="Here be dragons 🐉.">Home of forgotten features, deprecated dreams, and the “I’ll fix it later.”</p>
          </div>
        </div>
        <div class="repo-list collapsed" id="archived-list">
          <?php foreach ($archivedProjects as $project): ?>
            <div class="repo-card">
              <h3><a href="<?= h($project['link']) ?>" target="_blank" rel="noopener noreferrer"><?= h($project['name']) ?></a></h3>
              <p><?= nl2br(h($project['description'])) ?></p>
              <div class="repo-meta">
                <?php if ($project['custom']): ?><span class="badge">Custom Project (Non-GitHub)</span><?php endif; ?>
                <?php if ($project['private']): ?><span class="badge">Private Repository</span><?php endif; ?>
                <?php if ($project['language'] !== ''): ?><span class="badge"><?= h($project['language']) ?></span><?php endif; ?>
                <?php if ($project['stars'] > 0): ?><span class="badge">⭐ <?= $project['stars'] ?></span><?php endif; ?>
                <?php if ($project['updated'] !== ''): ?><span class="badge">Updated: <?= h($project['updated']) ?></span><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  </main>

  <footer>
    <div class="fake-status-bar">
      <span>Ln 420, Col 69</span><span>Errors: 0</span><span>Coffee: ∞</span><span>Uptime: ¯\_(ツ)_/¯</span>
    </div>
    <p>&copy; <?= date('Y') ?> ChilSoft. All rights reserved.</p>
    <p><b>Disclaimer:</b> This site and its contents are provided for informational and educational purposes only.</p>
    <p>Use any code, tools, or instructions at your own risk.</p>
    <p>We are not responsible for any damage to your device, data loss, or unintended consequences.</p>
    <p>Always proceed with care -- and make backups.</p>
  </footer>
  <script src="assets/js/main-script.js"></script>
</body>
</html>
