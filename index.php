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


function preserveSafeDescriptionHtml(string $text, array &$placeholders): string
{
    return preg_replace_callback(
        '/<\/?(?:a|br|strong|em|b|i|code|div|span)\b[^>]*>/i',
        static function (array $match) use (&$placeholders): string {
            $raw = $match[0];
            if (!preg_match('/^<\s*(\/?)\s*([a-z0-9]+)(.*?)>$/is', $raw, $parts)) {
                return '';
            }

            $closing = $parts[1] === '/';
            $tag = strtolower($parts[2]);
            $attributes = $parts[3] ?? '';
            $allowed = ['a', 'br', 'strong', 'em', 'b', 'i', 'code', 'div', 'span'];
            if (!in_array($tag, $allowed, true)) {
                return '';
            }

            if ($closing) {
                $safe = $tag === 'br' ? '' : '</' . $tag . '>';
            } elseif ($tag === 'br') {
                $safe = '<br>';
            } elseif (in_array($tag, ['strong', 'em', 'b', 'i', 'code'], true)) {
                $safe = '<' . $tag . '>';
            } elseif ($tag === 'a') {
                $href = '';
                if (preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/is', $attributes, $hrefMatch)) {
                    $candidate = html_entity_decode(trim($hrefMatch[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (preg_match('#^https?://#i', $candidate)) {
                        $href = $candidate;
                    }
                }
                $safe = $href === ''
                    ? '<span>'
                    : '<a href="' . h($href) . '" target="_blank" rel="noopener noreferrer">';
            } else {
                $class = '';
                $title = '';
                if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/is', $attributes, $classMatch)) {
                    $candidate = trim($classMatch[2]);
                    if (preg_match('/^[a-z0-9 _-]+$/i', $candidate)) {
                        $class = $candidate;
                    }
                }
                if (preg_match('/\btitle\s*=\s*(["\'])(.*?)\1/is', $attributes, $titleMatch)) {
                    $title = html_entity_decode(trim($titleMatch[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $safe = '<' . $tag
                    . ($class !== '' ? ' class="' . h($class) . '"' : '')
                    . ($title !== '' ? ' title="' . h($title) . '"' : '')
                    . '>';
            }

            $token = '@@CHILSOFT_HTML_' . count($placeholders) . '@@';
            $placeholders[$token] = $safe;
            return $token;
        },
        $text
    ) ?? $text;
}

function markdownInline(string $text): string
{
    $placeholders = [];
    $protected = preserveSafeDescriptionHtml($text, $placeholders);
    $escaped = h($protected);
    $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
    $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $escaped) ?? $escaped;
    $escaped = preg_replace_callback(
        '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
        static fn(array $m): string => '<a href="' . h($m[2]) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>',
        $escaped
    ) ?? $escaped;

    return strtr($escaped, $placeholders);
}

function markdownToHtml(string $markdown): string
{
    $lines = preg_split('/\R/', trim($markdown)) ?: [];
    $html = [];
    $paragraph = [];
    $inList = false;

    $flushParagraph = static function () use (&$paragraph, &$html): void {
        if ($paragraph !== []) {
            $html[] = '<p>' . markdownInline(implode(' ', $paragraph)) . '</p>';
            $paragraph = [];
        }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $flushParagraph();
            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }
            continue;
        }

        if (preg_match('/^(#{1,3})\s+(.+)$/', $trimmed, $m)) {
            $flushParagraph();
            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }
            $level = strlen($m[1]);
            $html[] = '<h' . $level . '>' . markdownInline($m[2]) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m)) {
            $flushParagraph();
            if (!$inList) {
                $html[] = '<ul>';
                $inList = true;
            }
            $html[] = '<li>' . markdownInline($m[1]) . '</li>';
            continue;
        }

        if ($inList) {
            $html[] = '</ul>';
            $inList = false;
        }
        $paragraph[] = $trimmed;
    }

    $flushParagraph();
    if ($inList) {
        $html[] = '</ul>';
    }

    return implode("\n", $html);
}

function loadRepoDescription(array $repo, array $config): string
{
    $owner = (string) ($repo['owner']['login'] ?? '');
    $name = (string) ($repo['name'] ?? '');
    $branch = (string) ($repo['default_branch'] ?? 'main');
    if ($owner === '' || $name === '') {
        return '';
    }

    $cacheSeconds = max(60, (int) ($config['github_cache_seconds'] ?? 900));
    $cacheDir = __DIR__ . '/cache/descriptions';
    $cacheFile = $cacheDir . '/' . preg_replace('/[^a-z0-9._-]+/i', '-', $owner . '-' . $name) . '.md';
    if (is_file($cacheFile) && filemtime($cacheFile) >= time() - $cacheSeconds) {
        return trim((string) file_get_contents($cacheFile));
    }

    $token = trim((string) ($config['github_token'] ?? ''));
    $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($name)
        . '/contents/description.md?ref=' . rawurlencode($branch);

    try {
        $file = githubRequest($url, $token);
        if (($file['encoding'] ?? '') !== 'base64' || !isset($file['content'])) {
            return '';
        }
        $decoded = base64_decode(str_replace(["\r", "\n"], '', (string) $file['content']), true);
        if ($decoded === false) {
            return '';
        }
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }
        file_put_contents($cacheFile, $decoded, LOCK_EX);
        return trim($decoded);
    } catch (Throwable $error) {
        return is_file($cacheFile) ? trim((string) file_get_contents($cacheFile)) : '';
    }
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

function normalizeRepo(array $repo, array $config): array
{
    $markdown = loadRepoDescription($repo, $config);
    return [
        'name' => (string) ($repo['name'] ?? 'Unnamed repository'),
        'link' => (string) ($repo['html_url'] ?? '#'),
        'description' => $markdown !== '' ? $markdown : (trim((string) ($repo['description'] ?? '')) ?: 'No description provided.'),
        'description_html' => $markdown !== '' ? markdownToHtml($markdown) : '<p>' . h(trim((string) ($repo['description'] ?? '')) ?: 'No description provided.') . '</p>',
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
        'description_html' => markdownToHtml((string) ($project['description'] ?? 'No description provided.')),
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
        $projects[] = normalizeRepo($repo, $config);
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
            <div class="repo-description"><?= $project['description_html'] ?></div>
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
              <div class="repo-description"><?= $project['description_html'] ?></div>
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
