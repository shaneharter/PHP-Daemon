create database daemon;
use daemon;
create table jobs ( id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, pid INT NOT NULL, job INT NOT NULL, worker VARCHAR (15) NOT NULL,
  is_complete TINYINT NOT NULL DEFAULT 0, retries INT NOT NULL DEFAULT 0, is_timeout TINYINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME DEFAULT NULL);