<?php
/**
 * OpenMind — Workspace Scanner & Markdown Parser
 *
 * Provides functions to scan the OpenClaw workspace directory,
 * parse markdown files into heading trees, and build the jsMind-compatible
 * data structure for the mindmap.
 */

function uid() {
    return substr(md5(uniqid(rand(), true)), 0, 16);
}

function headingColor($text, $config) {
    // Default color for all headings — no workspace-specific keywords assumed.
    // You can add your own keyword → color rules here if you want semantic coloring.
    return $config['color_heading'];
}

function resolveTree($indices, &$nodes) {
    $result = [];
    foreach ($indices as $i) {
        $node = $nodes[$i];
        $node['children'] = resolveTree($node['children'], $nodes);
        unset($node['_level']);
        $result[] = $node;
    }
    return $result;
}

function buildFileNode($file, $workspace, $config) {
    $rel      = str_replace($workspace . '/', '', $file);
    $basename = basename($file, '.md');
    $content  = @file_get_contents($file) ?: '';

    preg_match_all('/^(#{1,6})\s+(.+)$/m', $content, $hmatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    $items = [];
    $total = count($hmatches);
    for ($hi = 0; $hi < $total; $hi++) {
        $hm      = $hmatches[$hi];
        $level   = strlen($hm[1][0]);
        $heading = trim($hm[2][0]);
        if ($level === 1) continue;

        $headingEnd = $hm[0][1] + strlen($hm[0][0]);
        $bodyEnd    = strlen($content);
        for ($nj = $hi + 1; $nj < $total; $nj++) {
            $nextLevel = strlen($hmatches[$nj][1][0]);
            if ($nextLevel <= $level) {
                $bodyEnd = $hmatches[$nj][0][1];
                break;
            }
        }
        $fullBody = trim(substr($content, $headingEnd, $bodyEnd - $headingEnd));

        $immBody = $fullBody;
        if (preg_match('/^#{1,6}\s/m', $fullBody, $sm, PREG_OFFSET_CAPTURE)) {
            $immBody = trim(substr($fullBody, 0, $sm[0][1]));
        }

        $items[] = [
            'level'    => $level,
            'heading'  => $heading,
            'body'     => $immBody,
            'fullBody' => $fullBody,
        ];
    }

    if (!empty($items)) {
        $minLevel = min(array_column($items, 'level'));
        foreach ($items as &$item) { $item['level'] -= ($minLevel - 1); }
        unset($item);
    }

    $nodes = [];
    foreach ($items as $idx => $item) {
        $nodes[$idx] = [
            'id'       => uid(),
            'topic'    => $item['heading'],
            'data'     => [
                'body'     => $item['body'],
                'fullBody' => $item['fullBody'],
            ],
            '_level'   => $item['level'],
            'children' => [],
            'expanded' => false,
        ];
        $nodes[$idx]['data']['background-color'] = headingColor($item['heading'], $config);
        $nodes[$idx]['data']['color'] = $config['color_node_fg'];
    }

    $roots = [];
    $stack = [];
    foreach (array_keys($nodes) as $i) {
        $lvl = $nodes[$i]['_level'];
        while (!empty($stack) && $nodes[end($stack)]['_level'] >= $lvl) {
            array_pop($stack);
        }
        if (empty($stack)) {
            $roots[] = $i;
        } else {
            $nodes[end($stack)]['children'][] = $i;
        }
        $stack[] = $i;
    }

    $headingTree = resolveTree($roots, $nodes);

    return [
        'id'       => uid(),
        'topic'    => $basename,
        'data'     => [
            'file'             => $rel,
            'background-color' => $config['color_file'],
            'color'            => $config['color_node_fg'],
        ],
        'children' => $headingTree,
        'expanded' => false,
    ];
}

function assignBranchColor(&$node, $color) {
    $node['data']['leading-line-color'] = $color;
    $node['data']['branch-color'] = $color;
    if (!empty($node['children'])) {
        foreach ($node['children'] as &$child) {
            assignBranchColor($child, $color);
        }
        unset($child);
    }
}

/**
 * Build the full workspace tree for jsMind.
 */
function buildWorkspaceTree($config) {
    $workspace = rtrim($config['workspace_path'], '/');

    $rootFiles   = glob($workspace . '/*.md') ?: [];
    $memoryFiles = glob($workspace . '/memory/*.md') ?: [];

    $datedMemory = [];
    $otherMemory = [];
    foreach ($memoryFiles as $f) {
        if (preg_match('/\d{4}-\d{2}-\d{2}\.md$/', basename($f))) {
            $datedMemory[] = $f;
        } else {
            $otherMemory[] = $f;
        }
    }
    rsort($datedMemory);

    // Build children from root files, checking for matching subdirectories
    $children = [];
    $rootBasenames = [];
    foreach ($rootFiles as $file) {
        $node = buildFileNode($file, $workspace, $config);
        $subDirName = basename($file, '.md');
        $rootBasenames[] = $subDirName;
        $subDir = $workspace . '/' . $subDirName;
        if (is_dir($subDir)) {
            $subFiles = glob($subDir . '/*.md') ?: [];
            sort($subFiles);
            foreach ($subFiles as $sf) {
                $node['children'][] = buildFileNode($sf, $workspace, $config);
            }
        }
        $children[] = $node;
    }

    // Add non-dated memory files
    foreach ($otherMemory as $file) {
        $children[] = buildFileNode($file, $workspace, $config);
    }

    // Scan orphan subdirectories (no matching root .md file, excluding memory/)
    $allDirs = glob($workspace . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($allDirs as $dir) {
        $dirName = basename($dir);
        if ($dirName === 'memory') continue;
        if (in_array($dirName, $rootBasenames)) continue;
        $subFiles = glob($dir . '/*.md') ?: [];
        if (empty($subFiles)) continue;
        sort($subFiles);
        $dirChildren = [];
        foreach ($subFiles as $sf) {
            $dirChildren[] = buildFileNode($sf, $workspace, $config);
        }
        $children[] = [
            'id'       => uid(),
            'topic'    => str_replace(['-', '_'], ' ', $dirName),
            'data'     => [
                'background-color' => $config['color_orphan_dir'],
                'color'            => $config['color_node_fg'],
            ],
            'children' => $dirChildren,
            'expanded' => false,
        ];
    }

    // Dated memory history group
    $historyChildren = [];
    foreach ($datedMemory as $file) {
        $node = buildFileNode($file, $workspace, $config);
        $node['data']['background-color'] = $config['color_memory_entry'];
        $node['data']['color'] = $config['color_memory_entry_fg'];
        $historyChildren[] = $node;
    }

    if (!empty($historyChildren)) {
        $children[] = [
            'id'       => uid(),
            'topic'    => "\xF0\x9F\x93\x85 Memory History (" . count($historyChildren) . ')',
            'data'     => [
                'background-color' => $config['color_memory_group'],
                'color'            => $config['color_memory_group_fg'],
            ],
            'children' => $historyChildren,
            'expanded' => false,
        ];
    }

    // Assign branch colors
    $branchColors = $config['branch_colors'];
    foreach ($children as $i => &$child) {
        $color = $branchColors[$i % count($branchColors)];
        assignBranchColor($child, $color);
    }
    unset($child);

    // Alternate left/right for balanced layout
    foreach ($children as $i => &$child) {
        $child['direction'] = ($i % 2 === 0) ? 'right' : 'left';
    }
    unset($child);

    $displayTitle = getDisplayTitle($config);

    return [
        'meta'   => ['name' => $displayTitle, 'author' => 'openclaw', 'version' => '1.0'],
        'format' => 'node_tree',
        'data'   => [
            'id'       => 'root',
            'topic'    => $displayTitle,
            'data'     => [
                'background-color' => $config['color_root'],
                'color'            => $config['color_node_fg'],
            ],
            'children' => $children,
            'expanded' => true,
        ]
    ];
}
