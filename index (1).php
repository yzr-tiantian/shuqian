<?php
/**
 * 个人书签管理器
 * 功能：添加/编辑/删除书签、分类/标签筛选、搜索、导出Chrome格式书签
 * 环境：PHP + SQLite3（单文件部署，无需额外配置）
 */

// ==========================================
// 配置与初始化
// ==========================================
$dbFile = 'bookmarks.db';
initDatabase($dbFile); // 初始化数据库（首次运行创建表和示例数据）


// ==========================================
// 核心工具函数
// ==========================================

/**
 * 初始化数据库（创建表和示例数据）
 */
function initDatabase($dbFile) {
    $isNewDB = !file_exists($dbFile);
    
    try {
        $db = new SQLite3($dbFile);
        
        if ($isNewDB) {
            // 全新安装 - 创建完整表结构
            $db->exec("
                CREATE TABLE bookmarks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    url TEXT NOT NULL,
                    title TEXT NOT NULL,
                    category TEXT DEFAULT '未分类',
                    tags TEXT DEFAULT '',
                    favicon TEXT DEFAULT '',
                    icon_url TEXT DEFAULT '',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");

            // 插入示例书签
            $favicon = getFavicon('https://v.qq.com/');
            $db->exec("
                INSERT INTO bookmarks (url, title, category, tags, favicon, created_at)
                VALUES (
                    'https://v.qq.com/',
                    '腾讯视频',
                    '视频平台',
                    '腾讯视频',
                    '{$favicon}',
                    '2025-10-17'
                )
            ");
        } else {
            // 现有数据库 - 检查并添加缺失的字段
            $result = $db->query("PRAGMA table_info(bookmarks)");
            $columns = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $columns[] = $row['name'];
            }
            
            // 如果缺少 icon_url 字段，则添加
            if (!in_array('icon_url', $columns)) {
                $db->exec("ALTER TABLE bookmarks ADD COLUMN icon_url TEXT DEFAULT ''");
            }
        }
        
        $db->close();
    } catch (Exception $e) {
        die("数据库初始化失败: " . $e->getMessage());
    }
}

/**
 * 获取网站图标（优先使用官网favicon.ico，稳定无依赖）
 */
function getFavicon($url) {
    // 补全URL协议头
    if (!preg_match('/^https?:\/\//', $url)) {
        $url = 'http://' . $url;
    }

    // 提取完整域名（保留www.前缀，避免图标路径错误）
    $domain = parse_url($url, PHP_URL_HOST);
    if (!$domain) {
        // 域名解析失败时返回默认图标
        return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='32' height='32' fill='%236c757d'%3E%3Cpath d='M17 3H7a2 2 0 0 0-2 2v22a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 2v20H7V5h10z'/%3E%3Cpath d='M25 9h-2v6h-6v2h6v6h2v-6h6v-2h-6z'/%3E%3C/svg%3E";
    }

    return "https://{$domain}/favicon.ico";
}

/**
 * 导出书签为Chrome兼容格式
 */
function exportAsChromeFormat($bookmarks) {
    $html = <<<HTML
<!DOCTYPE NETSCAPE-Bookmark-file-1>
<!-- 自动生成的书签文件，请勿手动编辑 -->
<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">
<TITLE>书签栏</TITLE>
<H1>书签栏</H1>
<DL><p>
HTML;

    // 按分类分组
    $categories = [];
    foreach ($bookmarks as $bookmark) {
        $category = $bookmark['category'] ?: '未分类';
        $categories[$category][] = $bookmark;
    }

    // 生成分类文件夹和书签
    foreach ($categories as $category => $items) {
        $catName = htmlspecialchars($category);
        $html .= "<DT><H3 ADD_DATE=\"" . time() . "\">$catName</H3>\n<DL><p>";

        foreach ($items as $item) {
            $title = htmlspecialchars($item['title']);
            $url = htmlspecialchars($item['url']);
            $addDate = strtotime($item['created_at']);
            $tags = $item['tags'] ? "TAGS=\"{$item['tags']}\"" : '';
            $html .= "<DT><A HREF=\"$url\" ADD_DATE=\"$addDate\" $tags>$title</A>\n";
        }
        $html .= "</DL><p>";
    }

    return $html . '</DL><p>';
}


// ==========================================
// 数据库操作封装（减少重复代码）
// ==========================================
class BookmarkDB {
    private $db;

    public function __construct($dbFile) {
        $this->db = new SQLite3($dbFile);
        $this->db->enableExceptions(true); // 启用异常处理
    }

    public function query($sql, $params = []) {
        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, SQLITE3_TEXT);
        }
        return $stmt->execute();
    }

    public function fetchAll($sql, $params = []) {
        $result = $this->query($sql, $params);
        $data = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $data[] = $row;
        }
        return $data;
    }

    public function lastInsertId() {
        return $this->db->lastInsertRowID();
    }

    public function close() {
        $this->db->close();
    }
}


// ==========================================
// API请求处理（分离业务逻辑）
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action'])) {
    $action = $_GET['action'] ?? '';
    // 非导出操作统一返回JSON
    if ($action !== 'export') {
        header('Content-Type: application/json');
    }

    try {
        $db = new BookmarkDB($dbFile);
        $response = ['error' => '未知操作'];

        switch ($action) {
            // 导出书签
            case 'export':
                $bookmarks = $db->fetchAll("SELECT * FROM bookmarks ORDER BY category, created_at");
                $html = exportAsChromeFormat($bookmarks);
                header('Content-Type: text/html');
                header('Content-Disposition: attachment; filename="chrome_bookmarks_' . date('Ymd') . '.html"');
                echo $html;
                exit;

            // 更新书签
            case 'update':
                $input = json_decode(file_get_contents('php://input'), true);
                if (empty($input['id']) || empty($input['url']) || empty($input['title'])) {
                    $response = ['error' => 'ID、网址和标题不能为空'];
                    break;
                }

                $favicon = empty($input['icon_url']) ? getFavicon($input['url']) : $input['icon_url'];
                $db->query("
                    UPDATE bookmarks SET url = ?, title = ?, category = ?, tags = ?, favicon = ?, icon_url = ? WHERE id = ?
                ", [
                    $input['url'],
                    $input['title'],
                    $input['category'] ?? '未分类',
                    $input['tags'] ?? '',
                    $favicon,
                    $input['icon_url'] ?? '',
                    $input['id']
                ]);
                $response = ['success' => true];
                break;

            // 列表查询（支持搜索、分类、标签筛选）
            case 'list':
                $search = $_GET['search'] ?? '';
                $category = $_GET['category'] ?? '';
                $tag = $_GET['tag'] ?? '';

                $sql = "SELECT * FROM bookmarks WHERE 1=1";
                $params = [];

                if ($search) {
                    $sql .= " AND (title LIKE ? OR url LIKE ? OR tags LIKE ?)";
                    $searchTerm = "%$search%";
                    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
                }

                if ($category && $category !== 'all') {
                    $sql .= " AND category = ?";
                    $params[] = $category;
                }

                if ($tag && $tag !== 'all') {
                    $sql .= " AND tags LIKE ?";
                    $params[] = "%$tag%";
                }

                $sql .= " ORDER BY created_at DESC";
                $bookmarks = $db->fetchAll($sql, $params);

                // 处理图标显示逻辑
                foreach ($bookmarks as &$bookmark) {
                    // 优先使用自定义图标，其次使用自动获取的图标
                    if (!empty($bookmark['icon_url'])) {
                        $bookmark['display_icon'] = $bookmark['icon_url'];
                    } else if (empty($bookmark['favicon'])) {
                        $bookmark['display_icon'] = getFavicon($bookmark['url']);
                        // 更新数据库中的favicon
                        $db->query("UPDATE bookmarks SET favicon = ? WHERE id = ?", [
                            $bookmark['display_icon'],
                            $bookmark['id']
                        ]);
                    } else {
                        $bookmark['display_icon'] = $bookmark['favicon'];
                    }
                }
                $response = $bookmarks;
                break;

            // 添加书签
            case 'add':
                $input = json_decode(file_get_contents('php://input'), true);
                if (empty($input['url']) || empty($input['title'])) {
                    $response = ['error' => '网址和标题不能为空'];
                    break;
                }

                $favicon = empty($input['icon_url']) ? getFavicon($input['url']) : $input['icon_url'];
                $db->query("
                    INSERT INTO bookmarks (url, title, category, tags, favicon, icon_url) VALUES (?, ?, ?, ?, ?, ?)
                ", [
                    $input['url'],
                    $input['title'],
                    $input['category'] ?? '未分类',
                    $input['tags'] ?? '',
                    $favicon,
                    $input['icon_url'] ?? ''
                ]);
                $response = ['success' => true, 'id' => $db->lastInsertId()];
                break;

            // 删除书签
            case 'delete':
                $id = $_GET['id'] ?? '';
                if (empty($id)) {
                    $response = ['error' => 'ID不能为空'];
                    break;
                }

                $db->query("DELETE FROM bookmarks WHERE id = ?", [$id]);
                $response = ['success' => true];
                break;

            // 获取所有分类
            case 'categories':
                $response = $db->fetchAll("SELECT DISTINCT category FROM bookmarks ORDER BY category");
                $response = array_column($response, 'category'); // 提取分类字段
                break;

            // 获取所有标签（去重）
            case 'tags':
                $tagRows = $db->fetchAll("SELECT tags FROM bookmarks WHERE tags != ''");
                $allTags = [];
                foreach ($tagRows as $row) {
                    $tags = explode(',', $row['tags']);
                    foreach ($tags as $tag) {
                        $tag = trim($tag);
                        if (!empty($tag)) $allTags[] = $tag;
                    }
                }
                $response = array_values(array_unique($allTags));
                sort($response);
                break;
        }

        echo json_encode($response);
    } catch (Exception $e) {
        echo json_encode(['error' => '服务器错误: ' . $e->getMessage()]);
    } finally {
        if (isset($db)) $db->close();
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title>个人书签管理器</title>
    <link rel="icon" href="favicon.png">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #0d6efd;
            --secondary-color: #6c757d;
            --success-color: #198754;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --light-color: #f8f9fa;
            --dark-color: #212529;
        }

        [data-theme="dark"] {
            --bg-color: #1a1d20;
            --card-bg: #2d3035;
            --text-color: #e9ecef;
            --border-color: #495057;
            --muted-color: #adb5bd;
        }

        [data-theme="light"] {
            --bg-color: #f8f9fa;
            --card-bg: #ffffff;
            --text-color: #212529;
            --border-color: #dee2e6;
            --muted-color: #6c757d;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: all 0.3s ease;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            min-height: 100vh;
        }

        /* 紧凑卡片设计 */
        .bookmark-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            height: 100%;
            overflow: hidden;
            position: relative;
        }

        .bookmark-card:hover {
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
            transform: translateY(-3px);
            border-color: var(--primary-color);
        }

        .bookmark-icon {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            object-fit: cover;
            flex-shrink: 0;
        }

        .bookmark-content {
            flex: 1;
            min-width: 0;
        }

        .bookmark-title {
            font-weight: 600;
            font-size: 0.9rem;
            line-height: 1.3;
            margin-bottom: 0.25rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .bookmark-title a {
            color: var(--text-color);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .bookmark-title a:hover {
            color: var(--primary-color);
        }

        .bookmark-url {
            color: var(--muted-color);
            font-size: 0.75rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            margin-bottom: 0.5rem;
        }

        .bookmark-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.7rem;
            color: var(--muted-color);
        }

        .category-badge {
            background: linear-gradient(135deg, #0d6efd, #0a58ca);
            color: white;
            font-size: 0.65rem;
            padding: 0.2rem 0.5rem;
            border-radius: 8px;
            font-weight: 500;
            max-width: 80px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        [data-theme="dark"] .category-badge {
            background: linear-gradient(135deg, #1e3a8a, #1e40af);
        }

        .tag-badge {
            background-color: rgba(13, 110, 253, 0.1);
            color: var(--primary-color);
            font-size: 0.6rem;
            padding: 0.15rem 0.3rem;
            border-radius: 6px;
            margin: 0.05rem;
            display: inline-block;
            max-width: 60px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            border: 1px solid rgba(13, 110, 253, 0.2);
        }

        [data-theme="dark"] .tag-badge {
            background-color: rgba(13, 110, 253, 0.2);
            color: #bfdbfe;
        }

        .action-btn {
            background: none;
            border: none;
            color: var(--muted-color);
            padding: 0.3rem;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.8rem;
            border-radius: 4px;
        }

        .action-btn:hover {
            color: var(--primary-color);
            background-color: rgba(13, 110, 253, 0.1);
        }

        .action-btn.delete:hover {
            color: var(--danger-color);
            background-color: rgba(220, 53, 69, 0.1);
        }

        /* 导航栏样式优化 */
        .navbar-brand {
            font-weight: 700;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
        }

        .navbar-brand::before {
            content: "📚";
            font-size: 1.5rem;
            margin-right: 0.5rem;
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, #0d6efd, #0a58ca);
            border: none;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-primary-custom:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.3);
        }

        .filter-tag {
            background-color: rgba(13, 110, 253, 0.1);
            color: var(--primary-color);
            font-size: 0.8rem;
            padding: 0.3rem 0.6rem;
            border-radius: 10px;
            margin: 0.2rem;
            border: 1px solid rgba(13, 110, 253, 0.2);
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-block;
        }

        [data-theme="dark"] .filter-tag {
            background-color: rgba(13, 110, 253, 0.2);
            color: #bfdbfe;
        }

        .filter-tag:hover, .filter-tag.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            transform: translateY(-1px);
        }

        .search-box {
            border-radius: 10px;
            padding: 0.5rem 1rem 0.5rem 2.5rem;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            color: var(--text-color);
            width: 100%;
            transition: all 0.3s ease;
        }

        .search-box:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted-color);
        }

        .modal-content {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        .form-control-custom {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            border-radius: 8px;
            padding: 0.75rem;
            transition: all 0.3s ease;
        }

        .form-control-custom:focus {
            background-color: var(--card-bg);
            border-color: var(--primary-color);
            color: var(--text-color);
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
        }

        .category-btn {
            width: 100%;
            text-align: left;
            padding: 0.5rem 0.75rem;
            border: none;
            background: none;
            color: var(--text-color);
            border-radius: 6px;
            transition: all 0.2s ease;
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
        }

        .category-btn:hover, .category-btn.active {
            background: linear-gradient(135deg, rgba(13, 110, 253, 0.1), rgba(13, 110, 253, 0.05));
            color: var(--primary-color);
            transform: translateX(3px);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--muted-color);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .theme-toggle {
            border: none;
            background: none;
            color: var(--text-color);
            font-size: 1.25rem;
            padding: 0.5rem;
            border-radius: 50%;
            transition: all 0.2s ease;
        }

        .theme-toggle:hover {
            background-color: rgba(0, 0, 0, 0.1);
            transform: rotate(15deg);
        }

        [data-theme="dark"] .theme-toggle:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .navbar-custom {
            background-color: var(--card-bg) !important;
            border-bottom: 1px solid var(--border-color);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            padding: 0.75rem 0;
        }

        .filter-section {
            margin-bottom: 1.5rem;
        }

        .filter-section h6 {
            margin-bottom: 0.75rem;
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--primary-color);
        }

        .filter-tags-container, .categories-container {
            max-height: 150px;
            overflow-y: auto;
            padding-right: 0.5rem;
        }

        .clear-filters {
            background: none;
            border: none;
            color: var(--primary-color);
            font-size: 0.8rem;
            padding: 0.5rem;
            cursor: pointer;
            text-decoration: underline;
            transition: all 0.2s ease;
        }

        .clear-filters:hover {
            color: var(--danger-color);
        }

        .fade-in {
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* 图标预览 */
        .icon-preview {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            object-fit: cover;
            border: 1px solid var(--border-color);
            margin-right: 0.5rem;
        }

        .icon-preview-container {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        /* 侧边栏卡片 */
        .sidebar-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        /* 徽章样式 */
        .badge-active {
            background: linear-gradient(135deg, #198754, #157347);
            color: white;
        }

        /* 响应式优化 */
        @media (max-width: 768px) {
            .navbar-brand {
                font-size: 1.1rem;
            }
            
            .bookmark-card {
                margin-bottom: 1rem;
            }
            
            .sidebar {
                margin-bottom: 1.5rem;
            }
            
            .btn-primary-custom {
                padding: 0.4rem 0.8rem;
                font-size: 0.9rem;
            }
        }

        /* 滚动条样式 */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: var(--bg-color);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--muted-color);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-color);
        }
    </style>
</head>
<body data-theme="light">
    <!-- 导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                个人书签管理器
            </a>
            
            <div class="d-flex align-items-center">
                <button class="btn btn-outline-primary me-3" id="exportBtn">
                    <i class="fas fa-download me-1"></i>
                    导出书签
                </button>
                <button class="theme-toggle me-3" id="themeToggle">
                    <i class="fas fa-moon"></i>
                </button>
                <button class="btn btn-primary-custom" id="addBtn">
                    <i class="fas fa-plus me-1"></i>
                    添加书签
                </button>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- 侧边栏 -->
            <div class="col-lg-3 col-md-4 sidebar">
                <div class="sidebar-card p-3 mb-4">
                    <!-- 搜索框 -->
                    <div class="position-relative mb-4">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" class="form-control search-box" id="searchInput" placeholder="搜索书签...">
                    </div>
                    
                    <!-- 清除筛选按钮 -->
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-semibold mb-0">筛选</h6>
                        <button class="clear-filters" id="clearFilters">清除筛选</button>
                    </div>
                    
                    <!-- 分类筛选 -->
                    <div class="filter-section">
                        <h6>分类</h6>
                        <div class="categories-container" id="categoriesContainer">
                            <!-- 分类动态加载 -->
                        </div>
                    </div>
                    
                    <!-- 标签筛选 -->
                    <div class="filter-section">
                        <h6>标签</h6>
                        <div class="filter-tags-container" id="tagsContainer">
                            <!-- 标签动态加载 -->
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 主内容区 -->
            <div class="col-lg-9 col-md-8">
                <!-- 当前筛选状态 -->
                <div class="d-flex align-items-center mb-3" id="activeFilters">
                    <!-- 当前筛选状态会动态显示在这里 -->
                </div>
                
                <div class="row g-3" id="bookmarksContainer">
                    <!-- 书签动态加载 -->
                </div>
                
                <!-- 空状态 -->
                <div class="empty-state d-none" id="emptyState">
                    <i class="fas fa-bookmark"></i>
                    <h4 class="mb-3">还没有书签</h4>
                    <p class="mb-4">开始添加您的第一个书签吧</p>
                    <button class="btn btn-primary-custom" id="emptyAddBtn">
                        <i class="fas fa-plus me-2"></i>
                        添加书签
                    </button>
                </div>
                
                <!-- 搜索结果为空 -->
                <div class="empty-state d-none" id="noResults">
                    <i class="fas fa-search"></i>
                    <h4 class="mb-3">没有找到匹配的书签</h4>
                    <p>尝试调整搜索条件或分类筛选</p>
                </div>
            </div>
        </div>
    </div>

    <!-- 添加书签模态框 -->
    <div class="modal fade" id="bookmarkModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">添加书签</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="bookmarkForm">
                        <div class="mb-3">
                            <label for="urlInput" class="form-label">网址 *</label>
                            <input type="url" class="form-control form-control-custom" id="urlInput" required placeholder="https://example.com">
                        </div>
                        <div class="mb-3">
                            <label for="titleInput" class="form-label">标题 *</label>
                            <input type="text" class="form-control form-control-custom" id="titleInput" required placeholder="网站标题">
                        </div>
                        <div class="mb-3">
                            <label for="iconUrlInput" class="form-label">图标URL</label>
                            <div class="icon-preview-container">
                                <img id="iconPreview" src="" class="icon-preview d-none" alt="图标预览">
                                <input type="url" class="form-control form-control-custom" id="iconUrlInput" 
                                       placeholder="https://example.com/icon.png (可选，留空自动获取)">
                            </div>
                            <div class="form-text">可输入自定义图标URL，留空将自动获取网站图标</div>
                        </div>
                        <div class="mb-3">
                            <label for="categoryInput" class="form-label">分类</label>
                            <input type="text" class="form-control form-control-custom" id="categoryInput" 
                                   list="categoriesList" placeholder="未分类">
                            <datalist id="categoriesList">
                                <!-- 动态填充已有分类 -->
                            </datalist>
                        </div>
                        <div class="mb-3">
                            <label for="tagsInput" class="form-label">标签</label>
                            <input type="text" class="form-control form-control-custom" id="tagsInput" 
                                   list="tagsList" placeholder="用逗号分隔多个标签">
                            <datalist id="tagsList">
                                <!-- 动态填充已有标签 -->
                            </datalist>
                            <div class="form-text">例如: 技术,前端,PHP（可从下拉列表选择已有标签）</div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary-custom" id="saveBookmarkBtn">保存</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 编辑模态框 -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">编辑书签</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editForm">
                        <input type="hidden" id="editId">
                        <div class="mb-3">
                            <label for="editUrl" class="form-label">网址 *</label>
                            <input type="url" class="form-control form-control-custom" id="editUrl" required placeholder="https://example.com">
                        </div>
                        <div class="mb-3">
                            <label for="editTitle" class="form-label">标题 *</label>
                            <input type="text" class="form-control form-control-custom" id="editTitle" required placeholder="网站标题">
                        </div>
                        <div class="mb-3">
                            <label for="editIconUrl" class="form-label">图标URL</label>
                            <div class="icon-preview-container">
                                <img id="editIconPreview" src="" class="icon-preview" alt="图标预览">
                                <input type="url" class="form-control form-control-custom" id="editIconUrl" 
                                       placeholder="https://example.com/icon.png (可选，留空自动获取)">
                            </div>
                            <div class="form-text">可输入自定义图标URL，留空将自动获取网站图标</div>
                        </div>
                        <div class="mb-3">
                            <label for="editCategory" class="form-label">分类</label>
                            <input type="text" class="form-control form-control-custom" id="editCategory" 
                                   list="editCategoriesList" placeholder="未分类">
                            <datalist id="editCategoriesList">
                                <!-- 动态填充已有分类 -->
                            </datalist>
                        </div>
                        <div class="mb-3">
                            <label for="editTags" class="form-label">标签</label>
                            <input type="text" class="form-control form-control-custom" id="editTags" 
                                   list="editTagsList" placeholder="用逗号分隔多个标签">
                            <datalist id="editTagsList">
                                <!-- 动态填充已有标签 -->
                            </datalist>
                            <div class="form-text">例如: 技术,前端,PHP（可从下拉列表选择已有标签）</div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary-custom" id="saveEditBtn">保存修改</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 应用状态管理
        const state = {
            bookmarks: [],
            categories: [],
            tags: [],
            currentCategory: 'all',
            currentTag: 'all',
            searchQuery: '',
            searchTimeout: null
        };

        // 初始化
        document.addEventListener('DOMContentLoaded', () => {
            initTheme();
            loadData();
            setupEventListeners();
            setupIconPreview();
        });

        // 主题初始化与切换
        function initTheme() {
            const themeToggle = document.getElementById('themeToggle');
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            
            // 优先使用保存的主题，否则跟随系统
            const initialTheme = savedTheme || (prefersDark ? 'dark' : 'light');
            setTheme(initialTheme);
            
            themeToggle.addEventListener('click', () => {
                const newTheme = document.body.dataset.theme === 'dark' ? 'light' : 'dark';
                setTheme(newTheme);
            });
        }

        function setTheme(theme) {
            document.body.dataset.theme = theme;
            localStorage.setItem('theme', theme);
            document.querySelector('#themeToggle i').className = 
                theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }

        // 图标预览功能
        function setupIconPreview() {
            // 添加书签的图标预览
            const iconUrlInput = document.getElementById('iconUrlInput');
            const iconPreview = document.getElementById('iconPreview');
            
            iconUrlInput.addEventListener('input', function() {
                if (this.value) {
                    iconPreview.src = this.value;
                    iconPreview.classList.remove('d-none');
                } else {
                    iconPreview.classList.add('d-none');
                }
            });

            // 编辑书签的图标预览
            const editIconUrl = document.getElementById('editIconUrl');
            const editIconPreview = document.getElementById('editIconPreview');
            
            editIconUrl.addEventListener('input', function() {
                if (this.value) {
                    editIconPreview.src = this.value;
                }
            });
        }

        // 数据加载
        async function loadData() {
            try {
                await Promise.all([
                    loadBookmarks(),
                    loadCategories(),
                    loadTags()
                ]);
                updateDatalists(); // 更新输入框联想列表
                updateActiveFilters();
            } catch (error) {
                alert('加载数据失败: ' + error.message);
            }
        }

        // 更新分类/标签联想列表
        function updateDatalists() {
            // 添加书签的分类/标签联想
            document.getElementById('categoriesList').innerHTML = 
                state.categories.map(cat => `<option value="${cat}">`).join('');
            document.getElementById('tagsList').innerHTML = 
                state.tags.map(tag => `<option value="${tag}">`).join('');
            
            // 编辑书签的分类/标签联想（复用数据）
            document.getElementById('editCategoriesList').innerHTML = 
                document.getElementById('categoriesList').innerHTML;
            document.getElementById('editTagsList').innerHTML = 
                document.getElementById('tagsList').innerHTML;
        }

        // 加载书签列表
        async function loadBookmarks() {
            const params = new URLSearchParams();
            if (state.currentCategory !== 'all') params.append('category', state.currentCategory);
            if (state.currentTag !== 'all') params.append('tag', state.currentTag);
            if (state.searchQuery) params.append('search', state.searchQuery);
            
            try {
                const res = await fetch(`?action=list&${params}`);
                state.bookmarks = await res.json();
                renderBookmarks();
            } catch (error) {
                console.error('加载书签失败:', error);
                alert('加载书签失败');
            }
        }

        // 渲染书签列表 - 优化设计
        function renderBookmarks() {
            const container = document.getElementById('bookmarksContainer');
            const emptyState = document.getElementById('emptyState');
            const noResults = document.getElementById('noResults');
            
            if (state.bookmarks.length === 0) {
                container.innerHTML = '';
                // 区分"无数据"和"搜索无结果"
                if (state.searchQuery || state.currentCategory !== 'all' || state.currentTag !== 'all') {
                    emptyState.classList.add('d-none');
                    noResults.classList.remove('d-none');
                } else {
                    emptyState.classList.remove('d-none');
                    noResults.classList.add('d-none');
                }
                return;
            }
            
            // 隐藏空状态，渲染书签
            emptyState.classList.add('d-none');
            noResults.classList.add('d-none');
            
            container.innerHTML = state.bookmarks.map(bookmark => `
                <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6 fade-in">
                    <div class="bookmark-card p-3">
                        <div class="d-flex align-items-start mb-2">
                            <img src="${bookmark.display_icon}" alt="图标" class="bookmark-icon me-2" 
                                 onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\\'http://www.w3.org/2000/svg\\' width=\\'20\\' height=\\'20\\' fill=\\'%236c757d\\'%3E%3Cpath d=\\'M17 3H7a2 2 0 0 0-2 2v22a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 2v20H7V5h10z\\'/%3E%3Cpath d=\\'M25 9h-2v6h-6v2h6v6h2v-6h6v-2h-6z\\'/%3E%3C/svg%3E'">
                            <div class="bookmark-content">
                                <h6 class="bookmark-title">
                                    <a href="${bookmark.url}" target="_blank" title="${bookmark.title}">
                                        ${bookmark.title}
                                    </a>
                                </h6>
                                <p class="bookmark-url" title="${bookmark.url}">${getDomain(bookmark.url)}</p>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            ${bookmark.category && bookmark.category !== '未分类' ? 
                                `<span class="category-badge" title="${bookmark.category}">${bookmark.category}</span>` : 
                                '<span></span>'}
                            <div>
                                <button class="action-btn" onclick="editBookmark(${bookmark.id})" title="编辑">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="action-btn delete" onclick="deleteBookmark(${bookmark.id})" title="删除">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        
                        ${bookmark.tags ? `
                            <div class="mb-2">
                                ${bookmark.tags.split(',').slice(0, 3).map(tag => 
                                    `<span class="tag-badge" title="${tag.trim()}">${tag.trim()}</span>`).join('')}
                                ${bookmark.tags.split(',').length > 3 ? 
                                    `<span class="tag-badge">+${bookmark.tags.split(',').length - 3}</span>` : ''}
                            </div>
                        ` : ''}
                        
                        <div class="bookmark-meta">
                            <span>${formatDate(bookmark.created_at)}</span>
                        </div>
                    </div>
                </div>
            `).join('');
        }

        // 加载并渲染分类
        async function loadCategories() {
            try {
                const res = await fetch('?action=categories');
                state.categories = await res.json();
                renderCategories();
            } catch (error) {
                console.error('加载分类失败:', error);
            }
        }

        function renderCategories() {
            const container = document.getElementById('categoriesContainer');
            container.innerHTML = `
                <button class="category-btn ${state.currentCategory === 'all' ? 'active' : ''}" 
                        data-category="all">
                    <i class="fas fa-layer-group me-2"></i>全部分类
                </button>
                ${state.categories.map(category => `
                    <button class="category-btn ${state.currentCategory === category ? 'active' : ''}" 
                            data-category="${category}">
                        <i class="fas fa-folder me-2"></i>${category}
                    </button>
                `).join('')}
            `;
        }

        // 加载并渲染标签
        async function loadTags() {
            try {
                const res = await fetch('?action=tags');
                state.tags = await res.json();
                renderTags();
            } catch (error) {
                console.error('加载标签失败:', error);
            }
        }

        function renderTags() {
            const container = document.getElementById('tagsContainer');
            if (state.tags.length === 0) {
                container.innerHTML = '<p class="text-muted small">暂无标签</p>';
                return;
            }
            
            container.innerHTML = `
                <div class="filter-tag ${state.currentTag === 'all' ? 'active' : ''}" data-tag="all">
                    全部标签
                </div>
                ${state.tags.map(tag => `
                    <div class="filter-tag ${state.currentTag === tag ? 'active' : ''}" data-tag="${tag}">
                        ${tag}
                    </div>
                `).join('')}
            `;
        }

        // 更新筛选状态显示
        function updateActiveFilters() {
            const container = document.getElementById('activeFilters');
            const filters = [];
            
            if (state.currentCategory !== 'all') filters.push(`分类: ${state.currentCategory}`);
            if (state.currentTag !== 'all') filters.push(`标签: ${state.currentTag}`);
            if (state.searchQuery) filters.push(`搜索: ${state.searchQuery}`);
            
            container.innerHTML = filters.length ? 
                `<span class="me-2">当前筛选:</span>` + 
                filters.map(f => `<span class="badge badge-active me-2">${f}</span>`).join('') : 
                '';
        }

        // 事件监听
        function setupEventListeners() {
            // 搜索功能（防抖处理）
            document.getElementById('searchInput').addEventListener('input', (e) => {
                clearTimeout(state.searchTimeout);
                state.searchTimeout = setTimeout(() => {
                    state.searchQuery = e.target.value;
                    loadBookmarks();
                    updateActiveFilters();
                }, 300);
            });

            // 分类筛选
            document.addEventListener('click', (e) => {
                if (e.target.closest('.category-btn')) {
                    const btn = e.target.closest('.category-btn');
                    state.currentCategory = btn.dataset.category;
                    loadBookmarks();
                    renderCategories();
                    updateActiveFilters();
                }
            });

            // 标签筛选
            document.addEventListener('click', (e) => {
                if (e.target.classList.contains('filter-tag')) {
                    state.currentTag = e.target.dataset.tag;
                    loadBookmarks();
                    renderTags();
                    updateActiveFilters();
                }
            });

            // 清除筛选
            document.getElementById('clearFilters').addEventListener('click', () => {
                state.currentCategory = 'all';
                state.currentTag = 'all';
                state.searchQuery = '';
                document.getElementById('searchInput').value = '';
                loadBookmarks();
                renderCategories();
                renderTags();
                updateActiveFilters();
            });

            // 添加书签
            document.getElementById('addBtn').addEventListener('click', () => {
                document.getElementById('iconPreview').classList.add('d-none');
                new bootstrap.Modal(document.getElementById('bookmarkModal')).show();
            });
            document.getElementById('emptyAddBtn').addEventListener('click', () => {
                document.getElementById('iconPreview').classList.add('d-none');
                new bootstrap.Modal(document.getElementById('bookmarkModal')).show();
            });

            // 保存新书签
            document.getElementById('saveBookmarkBtn').addEventListener('click', addBookmark);

            // 导出书签
            document.getElementById('exportBtn').addEventListener('click', () => {
                window.location.href = '?action=export';
            });

            // 保存编辑
            document.getElementById('saveEditBtn').addEventListener('click', saveEdit);
        }

        // 添加书签
        async function addBookmark() {
            const url = document.getElementById('urlInput').value;
            const title = document.getElementById('titleInput').value;
            
            if (!url || !title) {
                alert('请填写网址和标题');
                return;
            }
            
            try {
                const res = await fetch('?action=add', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        url,
                        title,
                        category: document.getElementById('categoryInput').value || '未分类',
                        tags: document.getElementById('tagsInput').value,
                        icon_url: document.getElementById('iconUrlInput').value
                    })
                });
                
                const result = await res.json();
                if (result.success) {
                    bootstrap.Modal.getInstance(document.getElementById('bookmarkModal')).hide();
                    document.getElementById('bookmarkForm').reset();
                    alert('书签添加成功');
                    await loadData(); // 刷新数据
                } else {
                    alert(result.error || '添加失败');
                }
            } catch (error) {
                console.error('添加失败:', error);
                alert('添加失败，请重试');
            }
        }

        // 编辑书签
        function editBookmark(id) {
            const bookmark = state.bookmarks.find(b => b.id === id);
            if (!bookmark) return;
            
            document.getElementById('editId').value = bookmark.id;
            document.getElementById('editUrl').value = bookmark.url;
            document.getElementById('editTitle').value = bookmark.title;
            document.getElementById('editCategory').value = bookmark.category || '';
            document.getElementById('editTags').value = bookmark.tags || '';
            document.getElementById('editIconUrl').value = bookmark.icon_url || '';
            
            // 设置图标预览
            const editIconPreview = document.getElementById('editIconPreview');
            if (bookmark.icon_url) {
                editIconPreview.src = bookmark.icon_url;
            } else {
                editIconPreview.src = bookmark.display_icon;
            }
            
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }

        // 保存编辑
        async function saveEdit() {
            const id = document.getElementById('editId').value;
            const url = document.getElementById('editUrl').value;
            const title = document.getElementById('editTitle').value;
            
            if (!id || !url || !title) {
                alert('ID、网址和标题不能为空');
                return;
            }
            
            try {
                const res = await fetch('?action=update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id,
                        url,
                        title,
                        category: document.getElementById('editCategory').value || '未分类',
                        tags: document.getElementById('editTags').value,
                        icon_url: document.getElementById('editIconUrl').value
                    })
                });
                
                const result = await res.json();
                if (result.success) {
                    bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
                    alert('修改成功');
                    await loadData(); // 刷新数据
                } else {
                    alert(result.error || '修改失败');
                }
            } catch (error) {
                console.error('修改失败:', error);
                alert('修改失败，请重试');
            }
        }

        // 删除书签
        async function deleteBookmark(id) {
            if (!confirm('确定要删除这个书签吗？')) return;
            
            try {
                const res = await fetch(`?action=delete&id=${id}`);
                const result = await res.json();
                
                if (result.success) {
                    alert('书签删除成功');
                    await loadData(); // 刷新数据
                } else {
                    alert(result.error || '删除失败');
                }
            } catch (error) {
                console.error('删除失败:', error);
                alert('删除失败，请重试');
            }
        }

        // 辅助函数：提取域名
        function getDomain(url) {
            try {
                return new URL(url).hostname;
            } catch (e) {
                return url;
            }
        }

        // 辅助函数：格式化日期
        function formatDate(dateString) {
            return new Date(dateString).toLocaleDateString('zh-CN');
        }
    </script>
</body>
</html>