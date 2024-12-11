<?php
// 设置命令行输出编码为GBK（Windows中文系统默认编码）
if (PHP_SAPI === 'cli') {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        exec('chcp 65001'); // 设置命令行为UTF-8编码
    }
}

// 移除之前的header设置（因为CLI模式下不需要）
// header('Content-Type: text/plain; charset=utf-8');

// 设置内部编码
mb_internal_encoding('UTF-8');
// 设置HTTP输出编码
mb_http_output('UTF-8');
// 设置正则表达式编码
mb_regex_encoding('UTF-8');
// 设置输出缓冲
ob_start('mb_output_handler');
// 设置默认字符集
ini_set('default_charset', 'utf-8');

class AreaFetcher
{
    private $config;
    private $pdo;
    private $existingCodes = [];
    private $logFile;
    private $totalProcessed = 0;
    private $startTime;
    private $lastProgress = null;
    private $currentPath = [];

    public function __construct()
    {
        // 检查配置文件是否存在
        if (!file_exists('config.php')) {
            die("错误：配置文件 config.php 不存在！\n请复制 config.php.example 为 config.php 并修改配置。\n");
        }

        // 加载配置
        $this->config = require 'config.php';

        // 检查必要的配置项
        $this->validateConfig();
        
        // 打开日志文件
        $this->logFile = fopen('area_log.txt', 'wb');
        if ($this->logFile === false) {
            die('无法创建日志文件');
        }

        try {
            $dbConfig = $this->config['db'];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['database'],
                $dbConfig['charset']
            );
            
            $this->pdo = new PDO(
                $dsn,
                $dbConfig['username'],
                $dbConfig['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$dbConfig['charset']}"
                ]
            );
            $this->loadExistingCodes();
        } catch (PDOException $e) {
            $this->log('数据库连接失败: ' . $e->getMessage());
            die();
        }
    }

    // 添加配置验证方法
    private function validateConfig()
    {
        // 检查数据库配置
        $requiredDbConfig = ['host', 'port', 'database', 'username', 'password', 'charset', 'table'];
        foreach ($requiredDbConfig as $key) {
            if (!isset($this->config['db'][$key])) {
                die("错误：数据库配置缺少必要项 '{$key}'\n");
            }
        }

        // 检查API配置
        if (empty($this->config['api']['key'])) {
            die("错误：未设置腾讯地图API密钥\n");
        }

        // 检查API密钥是否是默认值
        if ($this->config['api']['key'] === 'YOUR-KEY-HERE') {
            die("错误：请修改腾讯地图API密钥\n");
        }

        // 检查其他必要配置
        if (!isset($this->config['api']['retry'])) {
            $this->config['api']['retry'] = 3; // 设置默认值
        }
        if (!isset($this->config['api']['delay'])) {
            $this->config['api']['delay'] = 200000; // 设置默认值
        }
        if (!isset($this->config['memory']['limit'])) {
            $this->config['memory']['limit'] = 256; // 设置默认值
        }
    }

    // 添加日志方法
    private function log($message)
    {
        $time = date('Y-m-d H:i:s');
        $memory = round(memory_get_usage() / 1024 / 1024, 2);
        $logMessage = "[{$time}] [内存: {$memory}MB] {$message}\n";
        fwrite($this->logFile, $logMessage);
        echo $logMessage;
    }

    // 预加载所有已存在的编码
    private function loadExistingCodes()
    {
        $table = $this->config['db']['table'];
        $stmt = $this->pdo->query("SELECT code FROM {$table} WHERE delete_time IS NULL");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->existingCodes[$row['code']] = true;
        }
    }

    // 获取腾讯地图数据
    private function fetchData($id = null, $retries = null)
    {
        $retries = $retries ?? $this->config['api']['retry'];
        $attempt = 0;
        while ($attempt < $retries) {
            try {
                $url = "https://apis.map.qq.com/ws/district/v1/getchildren";
                $params = ['key' => $this->config['api']['key']];
                if ($id !== null) {
                    $params['id'] = $id;
                }
                
                $url .= '?' . http_build_query($params);
                $this->log("正在请求URL: " . $url . " (尝试 " . ($attempt + 1) . "/$retries)");
                
                $response = file_get_contents($url);
                if ($response === false) {
                    throw new Exception('请求失败');
                }
                
                $data = json_decode($response, true);
                if ($data['status'] !== 0) {
                    // 如果是"错误的id"（status=363），则返回空数组
                    if ($data['status'] === 363) {
                        $this->log("ID不存在，跳过: {$id}");
                        return [];
                    }
                    throw new Exception('接口返回错误：' . ($data['message'] ?? '未知错误'));
                }
                
                return $data['result'][0] ?? [];
            } catch (Exception $e) {
                $attempt++;
                if ($attempt >= $retries) {
                    // 如果不是"错误的id"导致的失败，才终止脚本
                    if (strpos($e->getMessage(), '错误的id') === false) {
                        $this->log("错误：API请求失败次数超过{$retries}次，终止脚本执行");
                        $this->log("最后一次错误信息：" . $e->getMessage());
                        // 保留进度文件，不删除
                        exit(1); // 使用非零状态码退出
                    }
                    return []; // 如果是"错误的id"，返回空数组
                }
                $this->log("请求失败，等待重试...");
                sleep(2); // 失败后等待2秒再重试
            }
        }
    }

    // 批量保存数据到数据库
    private function batchSaveArea($items, $pid = 0)
    {
        if (empty($items)) {
            return [];
        }

        // 批量保存前检查内存
        $this->checkMemory();

        // 先批量查询已存在的记录
        $codes = array_column($items, 'id');
        $placeholders = str_repeat('?,', count($codes) - 1) . '?';
        $table = $this->config['db']['table'];
        $stmt = $this->pdo->prepare("SELECT id, code FROM {$table} WHERE code IN ($placeholders)");
        $stmt->execute($codes);
        $existingRecords = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $insertedIds = [];
        $this->pdo->beginTransaction();
        try {
            $values = [];
            $params = [];
            $currentTime = time();
            $index = 0;

            foreach ($items as $i => $item) {
                if (isset($existingRecords[$item['id']])) {
                    // 如果记录已存在，获取其ID
                    $insertedIds[$item['id']] = $existingRecords[$item['id']];
                    continue;
                }

                $values[] = "(:pid{$index}, :code{$index}, :name{$index}, :sort{$index}, :create_time{$index}, :update_time{$index}, NULL)";
                $params[":pid{$index}"] = $pid;
                $params[":code{$index}"] = $item['id'];
                $params[":name{$index}"] = $item['fullname'];
                $params[":sort{$index}"] = $i + 1;
                $params[":create_time{$index}"] = $currentTime;
                $params[":update_time{$index}"] = $currentTime;
                
                $this->existingCodes[$item['id']] = true;
                $index++;
            }

            if (!empty($values)) {
                $sql = "INSERT INTO {$table} (pid, code, name, sort, create_time, update_time, delete_time) VALUES " . 
                       implode(", ", $values) . 
                       " ON DUPLICATE KEY UPDATE pid = VALUES(pid)";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                
                // 获取插入的记录的ID
                foreach ($items as $item) {
                    if (!isset($insertedIds[$item['id']])) {
                        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE code = :code LIMIT 1");
                        $stmt->execute([':code' => $item['id']]);
                        $insertedIds[$item['id']] = $stmt->fetchColumn();
                    }
                }
            }

            $this->pdo->commit();
            return $insertedIds;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // 处理区域数据
    private function processArea($data, $pid = 0, $level = 1)
    {
        if (empty($data)) {
            return [];
        }

        // 每次处理新的层级时检查内存
        $this->checkMemory();

        // 只在第一级时加载断点信息
        if ($level == 1) {
            $this->lastProgress = $this->loadProgress();
            if ($this->lastProgress) {
                $this->log("发现断点记录，将从 {$this->lastProgress['path']} 继续执行");
            }
        }

        foreach ($data as $item) {
            // 每处理50条记录检查一次内存
            if ($this->totalProcessed % 50 == 0) {
                $this->checkMemory();
            }

            // 更新当前路径
            $this->currentPath[$level] = $item['fullname'];
            $currentPathStr = implode(' > ', array_filter($this->currentPath));
            
            // 检查是否需要跳过
            if ($this->lastProgress) {
                if ($this->shouldSkip($level, $item['id'], $currentPathStr)) {
                    if (!empty($currentPathStr)) {
                        $this->log("跳过: {$currentPathStr}");
                    }
                    continue;
                }
            }

            $this->log("处理: {$currentPathStr} (ID: {$item['id']}) 层级: {$level}");
            
            // 保存进度（每处理一个省级单位时保存）
            if ($level == 1) {
                $this->saveProgress($level, $item['id'], $item['fullname']);
            }

            // 直辖市特殊处理
            if ($level == 1 && $this->isDirectCity($item['fullname'])) {
                try {
                    // 1. 保存省级（如：北京市）
                    $provinceIds = $this->batchSaveArea([$item], 0);
                    $provinceId = $provinceIds[$item['id']] ?? null;

                    if ($provinceId) {
                        // 2. 保存市辖区
                        $cityItem = [
                            'id' => $item['id'] . '01',  // 添加后缀区分
                            'fullname' => '市辖区'
                        ];
                        $cityIds = $this->batchSaveArea([$cityItem], $provinceId);
                        $cityId = $cityIds[$cityItem['id']] ?? null;

                        if ($cityId) {
                            // 3. 获取区级数据
                            usleep($this->config['api']['delay']);
                            $districts = $this->fetchData($item['id']);
                            if (!empty($districts)) {
                                foreach ($districts as $district) {
                                    // 保存区级数据
                                    $districtIds = $this->batchSaveArea([$district], $cityId);
                                    $districtId = $districtIds[$district['id']] ?? null;

                                    if ($districtId) {
                                        // 4. 获取街道数据
                                        usleep($this->config['api']['delay']);
                                        $streets = $this->fetchData($district['id']);
                                        if (!empty($streets)) {
                                            $this->batchSaveArea($streets, $districtId);
                                        }
                                    }
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    $this->log("处理直辖市 {$item['fullname']} 失败：{$e->getMessage()}");
                }
            } else {
                // 非直辖市处理
                $itemIds = $this->batchSaveArea([$item], $pid);
                $itemId = $itemIds[$item['id']] ?? null;

                if ($itemId && $level < 4) {
                    try {
                        usleep($this->config['api']['delay']);
                        $children = $this->fetchData($item['id']);
                        if (!empty($children)) {
                            $this->processArea($children, $itemId, $level + 1);
                        }
                    } catch (Exception $e) {
                        $this->log("获取 {$currentPathStr} 的子级数据失败：{$e->getMessage()}");
                    }
                }
            }

            $this->totalProcessed++;
        }

        // 清理当前层级的路径记录
        unset($this->currentPath[$level]);
    }

    // 判断是否需要跳过当前项
    private function shouldSkip($level, $id, $path)
    {
        if (!$this->lastProgress) {
            return false;
        }

        if ($level == $this->lastProgress['level'] && $id == $this->lastProgress['last_id']) {
            $this->lastProgress = null; // 找到断点，清除标记
            $this->log("找到断点位置：{$path}，开始处理");
            return false;
        }

        return $this->lastProgress !== null;
    }

    // 保存进度
    private function saveProgress($level, $lastId, $path)
    {
        $progress = [
            'level' => $level,
            'last_id' => $lastId,
            'path' => $path,
            'timestamp' => time()
        ];
        file_put_contents('progress.json', json_encode($progress, JSON_UNESCAPED_UNICODE));
    }

    // 加载进度
    private function loadProgress()
    {
        if (file_exists('progress.json')) {
            $content = file_get_contents('progress.json');
            if ($content) {
                $progress = json_decode($content, true);
                if ($progress && isset($progress['path'])) {
                    return $progress;
                }
            }
        }
        return null;
    }

    // 开始获取数据
    public function start()
    {
        $this->startTime = time();
        try {
            $provinces = $this->fetchData();
            $total = count($provinces);
            if (!empty($provinces)) {
                $this->log("开始处理，共 {$total} 个省级行政区");
                $this->processArea($provinces, 0, 1);
                $this->showSummary();
                
                // 处理完成后删除进度文件
                if (file_exists('progress.json')) {
                    unlink('progress.json');
                    $this->log("进度文件已清除");
                }
            }
        } catch (Exception $e) {
            $this->log("错误：{$e->getMessage()}");
        }
    }

    private function showSummary()
    {
        $endTime = time();
        $duration = $endTime - $this->startTime;
        $hours = floor($duration / 3600);
        $minutes = floor(($duration % 3600) / 60);
        $seconds = $duration % 60;
        
        $this->log(sprintf(
            "处理完成！\n总计处理: %d 条数据\n耗时: %02d:%02d:%02d",
            $this->totalProcessed,
            $hours,
            $minutes,
            $seconds
        ));
    }

    public function __destruct()
    {
        // 关闭日志文件
        if ($this->logFile) {
            fclose($this->logFile);
        }
    }

    // 添加判断是否为直辖市的方法
    private function isDirectCity($name)
    {
        $directCities = [
            '北京市' => 'P110000',
            '上海市' => 'P310000',
            '天津市' => 'P120000',
            '重庆市' => 'P500000'
        ];
        return isset($directCities[$name]) ? $directCities[$name] : false;
    }

    // 修改内存检查方法，添加默认值
    private function checkMemory($limit = null)
    {
        $usedMemory = memory_get_usage(true) / 1024 / 1024;
        $memLimit = $limit ?? $this->config['memory']['limit'];
        
        if ($usedMemory > $memLimit * 0.8) {
            $this->log("内存使用达到{$usedMemory}MB，执行垃圾回收...");
            gc_collect_cycles();
            $this->existingCodes = array_filter($this->existingCodes);
        }
    }
}

// 运行脚本
$fetcher = new AreaFetcher();
$fetcher->start(); 