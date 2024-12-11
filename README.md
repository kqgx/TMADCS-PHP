# 腾讯地图行政区划数据采集脚本

本项目用于采集腾讯地图的省、市、区、街道四级行政区划数据，并将数据保存到MySQL数据库中。

## 数据结构

### 普通省份的层级结构
```
浙江省（第1级）
└── 杭州市（第2级）
    ├── 上城区（第3级）
    │   ├── 小营街道（第4级）
    │   └── ...
    └── ...
```

### 直辖市的层级结构
```
北京市（第1级）
└── 市辖区（第2级）
    ├── 东城区（第3级）
    │   ├── 东华门街道（第4级）
    │   └── ...
    └── ...
```

## 环境要求

- PHP 7.0+
- MySQL 5.6+
- PHP PDO扩展
- PHP mbstring扩展

## 配置说明

首次使用需要创建并修改配置文件：

1. 复制配置文件模板：
```bash
cp config.php.example config.php
```

2. 修改配置文件 `config.php`：

### 数据库配置
```php
'db' => [
    'host' => 'localhost',     // 数据库主机
    'port' => 3306,           // 端口
    'database' => 'area',     // 数据库名
    'username' => 'area',     // 用户名
    'password' => '123456',   // 密码
    'charset' => 'utf8mb4',   // 字符集
    'table' => 'area'         // 数据表名
]
```

### API配置
```php
'api' => [
    'key' => 'YOUR-KEY-HERE',  // 腾讯地图Key，必须修改
    'retry' => 3,              // 请求失败重试次数
    'delay' => 200000          // 请求间隔（微秒）
]
```

### 内存配置
```php
'memory' => [
    'limit' => 256            // 内存限制（MB）
]
```

### 配置项说明

1. 必需配置：
   - 数据库连接信息（host, port, database, username, password, charset, table）
   - 腾讯地图API密钥（api.key）

2. 可选配置（有默认值）：
   - API重试次数：默认3次
   - 请求间隔：默认200ms
   - 内存限制：默认256MB

3. 配置检查：
   - 脚本会自动检查配置文件是否存在
   - 验证必需配置项是否完整
   - 检查API密钥是否已修改
   - 为可选配置项设置默认值

## 文件说明

- `config.php.example`: 配置文件模板
- `config.php`: 实际配置文件（需手动创建）
- `area.sql`: 数据库表结构文件
- `fetch_area.php`: 数据采集脚本
- `area_log.txt`: 执行日志文件（自动生成）
- `progress.json`: 断点续传进度文件（自动生成）

## 使用方法

1. 复制并修改配置文件：
```bash
cp config.php.example config.php
# 修改 config.php 中的配置项，特别是腾讯地图API密钥
```

2. 创建数据库和用户
```sql
CREATE DATABASE area;
GRANT ALL PRIVILEGES ON area.* TO 'area'@'localhost' IDENTIFIED BY '123456';
FLUSH PRIVILEGES;
```

3. 导入表结构
```bash
mysql -u area -p area < area.sql
```

4. 运行采集脚本
```bash
php fetch_area.php
```

## 功能特性

1. 四级数据结构：省/市/区/街道 完整的行政区划体系
2. 直辖市处理：自动处理直辖市的特殊结构
3. 断点续传：支持中断后从上次位置继续执行
4. 智能错误处理：区分不同API错误类型，智能处理
5. 路径显示：实时显示完整的行政区划路径
6. 内存管理：自动监控和优化内存使用

## 错误处理机制

1. API请求错误：
   - 自动重试最多3次，每次间隔2秒
   - "错误的id"（无下级数据）自动跳过继续执行
   - 其他API错误超过重试次数后终止脚本

2. 内存管理：
   - 定期检查内存使用情况
   - 超过阈值（80%）时自动回收
   - 清理无效的缓存数据

3. 断点续传：
   - 每个省级单位处理完成后保存进度
   - 意外中断时自动保留进度文件
   - 重启后从断点继续执行

4. 日志记录：
   - 记录完整的处理路径
   - 显示内存使用情况
   - 保存详细的错误信息

5. 配置错误：
   - 配置文件不存在时提示复制模板
   - 必需配置项缺失时报错
   - API密钥未修改时终止执行
   - 可选配置缺失时使用默认值

## 注意事项

1. 运行前清空数据表：
```sql
TRUNCATE TABLE area;  -- area 为配置文件中设置的表名
```

2. 查看执行日志：
```bash
tail -f area_log.txt
```

## License

MIT License
```

这个README文件包含了：
1. 项目说明
2. 环境要求
3. 配置信息
4. 使用方法
5. 数据结构说明
6. 断点续传功能
7. 注意事项
8. 错误处理方法

您可以根据实际需求调整或补充其中的内容。