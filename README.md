### Create migration user
```mysql
create database db123;
CREATE USER 'migration_user'@'%' IDENTIFIED WITH mysql_native_password BY 'pwd123';
GRANT ALL PRIVILEGES ON db123.* TO 'migration_user'@'%';
FLUSH PRIVILEGES;
```

### Create migration table
```mysql
CREATE TABLE `migration_log` (
                                 `id` int unsigned NOT NULL AUTO_INCREMENT,
                                 `index` int unsigned NOT NULL,
                                 `filename` varchar(255) NOT NULL,
                                 `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                 PRIMARY KEY (`id`),
                                 UNIQUE KEY `migration_log_pk` (`filename`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

```

### Test
```bash
docker run -ti --rm -v "$(pwd)":/data/ --workdir /data/ --network host dfuhbu/php8.3-cli-dev:1 bash
```