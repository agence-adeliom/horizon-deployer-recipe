-- Une base par environnement, toutes sur le même serveur MariaDB (10.11 : sans les collations
-- utf8mb4_0900_*, que MariaDB 11.4.5+ accepte comme alias).
CREATE DATABASE local;
CREATE DATABASE staging;
CREATE DATABASE recette;
CREATE DATABASE production;
CREATE DATABASE reseau_a;
CREATE DATABASE reseau_b;
CREATE USER 'wp'@'%' IDENTIFIED BY 'wp';
GRANT ALL PRIVILEGES ON *.* TO 'wp'@'%';
FLUSH PRIVILEGES;
