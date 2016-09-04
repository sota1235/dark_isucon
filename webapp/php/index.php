<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require 'vendor/autoload.php';

$_SERVER += ['PATH_INFO' => $_SERVER['REQUEST_URI']];
$_SERVER['SCRIPT_NAME'] = '/' . basename($_SERVER['SCRIPT_FILENAME']);
$file = dirname(__DIR__) . '/public' . $_SERVER['REQUEST_URI'];
if (is_file($file)) {
    if (PHP_SAPI == 'cli-server') return false;
    $mimetype = [
        'js' => 'application/javascript',
        'css' => 'text/css',
        'ico' => 'image/vnd.microsoft.icon',
    ][pathinfo($file, PATHINFO_EXTENSION)] ?? false;
    if ($mimetype) {
        header("Content-Type: {$mimetype}");
        echo file_get_contents($file); exit;
    }
}

const POSTS_PER_PAGE = 20;
const UPLOAD_LIMIT = 10 * 1024 * 1024;

require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/Cache.php';

$config = [
    'settings' => [
        'public_folder' => dirname(dirname(__DIR__)) . '/public',
        'db' => [
            'host' => $_ENV['ISUCONP_DB_HOST'] ?? 'localhost',
            'port' => $_ENV['ISUCONP_DB_PORT'] ?? 3306,
            'username' => $_ENV['ISUCONP_DB_USER'] ?? 'root',
            'password' => $_ENV['ISUCONP_DB_PASSWORD'] ?? null,
            'database' => $_ENV['ISUCONP_DB_NAME'] ?? 'isuconp',
        ]
    ]
];

// memcached session
ini_set('session.save_handler', 'memcached');
ini_set('session.save_path', '127.0.0.1:11211');

session_start();

// dependency
$app = new \Slim\App($config);
$container = $app->getContainer();
$container['db'] = function ($c) {
    $config = $c['settings'];
    return new PDO(
        "mysql:dbname={$config['db']['database']};host={$config['db']['host']};port={$config['db']['port']};charset=utf8mb4",
        $config['db']['username'],
        $config['db']['password']
    );
};

$container['view'] = function ($c) {
    return new class(__DIR__ . '/views/') extends \Slim\Views\PhpRenderer {
        public function render(\Psr\Http\Message\ResponseInterface $response, $template, array $data = []) {
            $data += ['view' => $template];
            return parent::render($response, 'layout.php', $data);
        }
    };
};

$container['flash'] = function () {
    return new \Slim\Flash\Messages;
};

$container['helper'] = function ($c) {
    return new class($c) {
        public function __construct($c) {
            $this->db = $c['db'];
        }

        public function db() {
            return $this->db;
        }

        public function db_initialize() {
            $db = $this->db();
            $sql = [];
            $sql[] = 'DELETE FROM users WHERE id > 1000';
            $sql[] = 'DELETE FROM posts WHERE id > 10000';
            $sql[] = 'DELETE FROM comments WHERE id > 100000';
            $sql[] = 'UPDATE users SET del_flg = 0';
            $sql[] = 'UPDATE users SET del_flg = 1 WHERE id % 50 = 0';
            foreach($sql as $s) {
                $db->query($s);
            }
        }

        public function fetch_first($query, ...$params) {
            $db = $this->db();
            $ps = $db->prepare($query);
            $ps->execute($params);
            $result = $ps->fetch();
            $ps->closeCursor();
            return $result;
        }

        public function try_login($account_name, $password) {

            $filePath = './cache/users.json';
            // ファイル内にユーザ情報があればそれを使う
            $users = file_get_contents($filePath);
            
            // ファイルが無いとき
            if(!$users){
                $db = $this->db();
                $ps = $db->prepare('SELECT * FROM users WHERE del_flg = 0');
		            $ps->execute($params);
                $value = $ps->fetchAll(PDO::FETCH_ASSOC);
                $ps->closeCursor();
                // ユーザ情報全ツッコミ
                $users = json_encode($value);
                file_put_contents($filePath, $users);
            }

            $users = json_decode($users);
            foreach($users as $user) {
              if ($user['account_name'] === $account_name) {
                break;
              }
            }
            var_dump($user);
            exit;

            $user = $this->fetch_first('SELECT * FROM users WHERE account_name = ? AND del_flg = 0', $account_name);
            if ($user !== false && calculate_passhash($user['account_name'], $password) == $user['passhash']) {
                return $user;
            }
            return null;
        }

        public function get_session_user() {
            if (isset($_SESSION['user'], $_SESSION['user']['id'])) {
                return $this->fetch_first('SELECT * FROM `users` WHERE `id` = ?', $_SESSION['user']['id']);
            }
            return null;
        }

        public function make_posts(array $results, $options = []) {
            $options += ['all_comments' => false];
            $all_comments = $options['all_comments'];

            $posts = [];
            foreach ($results as $post) {
                $post['comment_count'] = $this->fetch_first('SELECT COUNT(*) AS `count` FROM `comments` WHERE `post_id` = ?', $post['id'])['count'];
                $query = 'SELECT * FROM `comments` WHERE `post_id` = ? ORDER BY `created_at` DESC';
                if (!$all_comments) {
                    $query .= ' LIMIT 3';
                }

                $ps = $this->db()->prepare($query);
                $ps->execute([$post['id']]);
                $comments = $ps->fetchAll(PDO::FETCH_ASSOC);
                foreach ($comments as &$comment) {
                    $comment['user'] = $this->fetch_first('SELECT * FROM `users` WHERE `id` = ?', $comment['user_id']);
                }
                unset($comment);
                $post['comments'] = array_reverse($comments);

                $post['user'] = $this->fetch_first('SELECT * FROM `users` WHERE `id` = ?', $post['user_id']);
                if ($post['user']['del_flg'] == 0) {
                    $posts[] = $post;
                }
                if (count($posts) >= POSTS_PER_PAGE) {
                    break;
                }
            }
            return $posts;
        }

    };
};

// ------- helper method for view

function escape_html($h) {
    return htmlspecialchars($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function flash($key) {
    $flash = new \Slim\Flash\Messages;
    return $flash->getMessage($key)[0];
}

function redirect(Response $response, $location, $status) {
    return $response->withStatus($status)->withHeader('Location', $location);
}

function image_url($post) {
    $ext = '';
    if ($post['mime'] === 'image/jpeg') {
        $ext = '.jpg';
    } else if ($post['mime'] === 'image/png') {
        $ext = '.png';
    } else if ($post['mime'] === 'image/gif') {
        $ext = '.gif';
    }
    return "/image/{$post['id']}{$ext}";
}

function validate_user($account_name, $password) {
    if (!(preg_match('/\A[0-9a-zA-Z_]{3,}\z/', $account_name) && preg_match('/\A[0-9a-zA-Z_]{6,}\z/', $password))) {
        return false;
    }
    return true;
}

function digest($src) {
    // opensslのバージョンによっては (stdin)= というのがつくので取る
    $src = escapeshellarg($src);
    return trim(`printf "%s" {$src} | openssl dgst -sha512 | sed 's/^.*= //'`);
}

function calculate_salt($account_name) {
    return digest($account_name);
}

function calculate_passhash($account_name, $password) {
    $salt = calculate_salt($account_name);
    return digest("{$password}:{$salt}");
}

// -------- 

$app->get('/initialize', function (Request $request, Response $response) {
    $this->get('helper')->db_initialize();
    return $response;
});

$app->get('/login', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }
    return $this->view->render($response, 'login.php', [
        'me' => null
    ]);
});

$app->post('/login', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }

    $db = $this->get('db');
    $params = $request->getParams();
    $user = $this->get('helper')->try_login($params['account_name'], $params['password']);

    if ($user) {
        $_SESSION['user'] = [
          'id' => $user['id'],
        ];
        return redirect($response, '/', 302);
    } else {
        $this->flash->addMessage('notice', 'アカウント名かパスワードが間違っています');
        return redirect($response, '/login', 302);
    }
});

$app->get('/register', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }
    return $this->view->render($response, 'register.php', [
        'me' => null
    ]);
});


$app->post('/register', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user()) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParams();
    $account_name = $params['account_name'];
    $password = $params['password'];

    $validated = validate_user($account_name, $password);
    if (!$validated) {
        $this->flash->addMessage('notice', 'アカウント名は3文字以上、パスワードは6文字以上である必要があります');
        return redirect($response, '/register', 302);
    }

    $user = $this->get('helper')->fetch_first('SELECT 1 FROM users WHERE `account_name` = ?', $account_name);
    if ($user) {
        $this->flash->addMessage('notice', 'アカウント名がすでに使われています');
        return redirect($response, '/register', 302);
    }

    $db = $this->get('db');
    $ps = $db->prepare('INSERT INTO `users` (`account_name`, `passhash`) VALUES (?,?)');
    $ps->execute([
        $account_name,
        calculate_passhash($account_name, $password)
    ]);
    $_SESSION['user'] = [
        'id' => $db->lastInsertId(),
    ];
    return redirect($response, '/', 302);
});

$app->get('/logout', function (Request $request, Response $response) {
    unset($_SESSION['user']);
    return redirect($response, '/', 302);
});

$app->get('/', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    $db = $this->get('db');
    $ps = $db->prepare('SELECT `id`, `user_id`, `body`, `mime`, `created_at` FROM `posts` ORDER BY `created_at` DESC');
    $ps->execute();
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results);

    return $this->view->render($response, 'index.php', ['posts' => $posts, 'me' => $me]);
});

$app->get('/posts', function (Request $request, Response $response) {
    $params = $request->getParams();
    $max_created_at = $params['max_created_at'] ?? null;
    $db = $this->get('db');
    $ps = $db->prepare('SELECT `id`, `user_id`, `body`, `mime`, `created_at` FROM `posts` WHERE `created_at` <= ? ORDER BY `created_at` DESC');
    $ps->execute([$max_created_at === null ? null : $max_created_at]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results);

    return $this->view->render($response, 'posts.php', ['posts' => $posts]);
});

$app->get('/posts/{id}', function (Request $request, Response $response, $args) {
    $db = $this->get('db');
    $ps = $db->prepare('SELECT * FROM `posts` WHERE `id` = ?');
    $ps->execute([$args['id']]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results, ['all_comments' => true]);

    if (count($posts) == 0) {
        return $response->withStatus(404)->write('404');
    }

    $post = $posts[0];

    $me = $this->get('helper')->get_session_user();

    return $this->view->render($response, 'post.php', ['post' => $post, 'me' => $me]);
});

$app->post('/', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/login', 302);
    }

    $params = $request->getParams();
    if ($params['csrf_token'] !== session_id()) {
        return $response->withStatus(422)->write('422');
    }

    if ($_FILES['file']) {
        $mime = '';
        // 投稿のContent-Typeからファイルのタイプを決定する
        if (strpos($_FILES['file']['type'], 'jpeg') !== false) {
            $mime = 'image/jpeg';
        } elseif (strpos($_FILES['file']['type'], 'png') !== false) {
            $mime = 'image/png';
        } elseif (strpos($_FILES['file']['type'], 'gif') !== false) {
            $mime = 'image/gif';
        } else {
            $this->flash->addMessage('notice', '投稿できる画像形式はjpgとpngとgifだけです');
            return redirect($response, '/', 302);
        }

        if (strlen(file_get_contents($_FILES['file']['tmp_name'])) > UPLOAD_LIMIT) {
            $this->flash->addMessage('notice', 'ファイルサイズが大きすぎます');
            return redirect($response, '/', 302);
        }

        $db = $this->get('db');
        $query = 'INSERT INTO `posts` (`user_id`, `mime`, `imgdata`, `body`) VALUES (?,?,?,?)';
        $ps = $db->prepare($query);
        $ps->execute([
          $me['id'],
          $mime,
          file_get_contents($_FILES['file']['tmp_name']),
          $params['body'],
        ]);
        $pid = $db->lastInsertId();
        return redirect($response, "/posts/{$pid}", 302);
    } else {
        $this->flash->addMessage('notice', '画像が必須です');
        return redirect($response, '/', 302);
    }
});

$app->get('/image/{id}.{ext}', function (Request $request, Response $response, $args) {
    if ($args['id'] == 0) {
        return '';
    }

    $post = $this->get('helper')->fetch_first('SELECT * FROM `posts` WHERE `id` = ?', $args['id']);

    if (($args['ext'] == 'jpg' && $post['mime'] == 'image/jpeg') ||
        ($args['ext'] == 'png' && $post['mime'] == 'image/png') ||
        ($args['ext'] == 'gif' && $post['mime'] == 'image/gif')) {
        return $response->withHeader('Content-Type', $post['mime'])
                        ->write($post['imgdata']);
    }
    return $response->withStatus(404)->write('404');
});

$app->post('/comment', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/login', 302);
    }

    $params = $request->getParams();
    if ($params['csrf_token'] !== session_id()) {
        return $response->withStatus(422)->write('422');
    }

    // TODO: /\A[0-9]\Z/ か確認
    if (preg_match('/[0-9]+/', $params['post_id']) == 0) {
        return $response->write('post_idは整数のみです');
    }
    $post_id = $params['post_id'];

    $query = 'INSERT INTO `comments` (`post_id`, `user_id`, `comment`) VALUES (?,?,?)';
    $ps = $this->get('db')->prepare($query);
    $ps->execute([
        $post_id,
        $me['id'],
        $params['comment']
    ]);

    return redirect($response, "/posts/{$post_id}", 302);
});

$app->get('/admin/banned', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/login', 302);
    }

    if ($me['authority'] == 0) {
        return $response->withStatus(403)->write('403');
    }

    $db = $this->get('db');
    $ps = $db->prepare('SELECT * FROM `users` WHERE `authority` = 0 AND `del_flg` = 0 ORDER BY `created_at` DESC');
    $ps->execute();
    $users = $ps->fetchAll(PDO::FETCH_ASSOC);

    return $this->view->render($response, 'banned.php', ['users' => $users, 'me' => $me]);
});

$app->post('/admin/banned', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/login', 302);
    }

    if ($me['authority'] == 0) {
        return $response->withStatus(403)->write('403');
    }

    $params = $request->getParams();
    if ($params['csrf_token'] !== session_id()) {
        return $response->withStatus(422)->write('422');
    }

    $db = $this->get('db');
    $query = 'UPDATE `users` SET `del_flg` = ? WHERE `id` = ?';
    foreach ($params['uid'] as $id) {
        $ps = $db->prepare($query);
        $ps->execute([1, $id]);
    }

    return redirect($response, '/admin/banned', 302);
});

$app->get('/@{account_name}', function (Request $request, Response $response, $args) {
    $db = $this->get('db');
    $user = $this->get('helper')->fetch_first('SELECT * FROM `users` WHERE `account_name` = ? AND `del_flg` = 0', $args['account_name']);

    if ($user === false) {
        return $response->withStatus(404)->write('404');
    }

    $ps = $db->prepare('SELECT `id`, `user_id`, `body`, `created_at`, `mime` FROM `posts` WHERE `user_id` = ? ORDER BY `created_at` DESC');
    $ps->execute([$user['id']]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results);

    $comment_count = $this->get('helper')->fetch_first('SELECT COUNT(*) AS count FROM `comments` WHERE `user_id` = ?', $user['id'])['count'];

    $ps = $db->prepare('SELECT `id` FROM `posts` WHERE `user_id` = ?');
    $ps->execute([$user['id']]);
    $post_ids = array_column($ps->fetchAll(PDO::FETCH_ASSOC), 'id');
    $post_count = count($post_ids);

    $commented_count = 0;
    if ($post_count > 0) {
        $placeholder = implode(',', array_fill(0, count($post_ids), '?'));
        $commented_count = $this->get('helper')->fetch_first("SELECT COUNT(*) AS count FROM `comments` WHERE `post_id` IN ({$placeholder})", ...$post_ids)['count'];
    }

    $me = $this->get('helper')->get_session_user();

    return $this->view->render($response, 'user.php', ['posts' => $posts, 'user' => $user, 'post_count' => $post_count, 'comment_count' => $comment_count, 'commented_count'=> $commented_count, 'me' => $me]);
});

$app->run();
