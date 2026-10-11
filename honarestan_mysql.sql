-- ==========================================================================
--  هنرستان شهید رجایی — سامانه جامع مدیریت آموزشی
--  SQL Dump ساختار پایگاه داده (MySQL / MariaDB)
--  تاریخ تولید: 2026-10-11 12:48:33
--  کاربرد: می‌توانید این فایل را در phpMyAdmin وارد (Import) کنید.
--  توجه: پس از درج مدیر کل با رمز هش‌شده، رمز پیش‌فرض «admin123» است.
-- ==========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `honarestan` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `honarestan`;

DROP TABLE IF EXISTS `admins`;
CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(120) NOT NULL,
            username  VARCHAR(60)  NOT NULL UNIQUE,
            password  VARCHAR(255) NOT NULL,
            role      VARCHAR(20)  NOT NULL DEFAULT 'teacher',
            is_super  INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `admins` (`full_name`,`username`,`password`,`role`,`is_super`,`is_active`,`created_at`) VALUES
('مدیر هنرستان', 'admin', '$2y$12$4Ahn4WhCbkNNmd3U9znmw.0YW2KIldO/aprIWaqvASAA7MqlMV31G', 'super', 1, 1, '2026-10-11 12:48:33');

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE IF NOT EXISTS permissions (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            name  VARCHAR(60)  NOT NULL UNIQUE,
            label VARCHAR(120) NOT NULL,
            pgroup VARCHAR(60)  NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`name`, `label`, `pgroup`) VALUES
('view_students', 'مشاهده دانش‌آموزان', 'دانش‌آموزان'),
('add_student', 'افزودن دانش‌آموز', 'دانش‌آموزان'),
('edit_student', 'ویرایش دانش‌آموز', 'دانش‌آموزان'),
('delete_student', 'حذف دانش‌آموز', 'دانش‌آموزان'),
('register_delay', 'ثبت تأخیر', 'ثبت موارد'),
('register_absence', 'ثبت غیبت', 'ثبت موارد'),
('register_discipline', 'ثبت موارد انضباطی', 'ثبت موارد'),
('register_dress', 'ثبت تخلف پوششی', 'ثبت موارد'),
('export_pdf', 'خروجی PDF / چاپ', 'گزارش‌ها'),
('teacher_panel', 'پنل معلم (کلاس‌ها)', 'پنل معلم'),
('view_all_records', 'مشاهده سوابق همه کاربران', 'گزارش‌ها'),
('manage_admins', 'مدیریت ادمین‌ها و پرمیشن‌ها', 'مدیریت سیستم');

DROP TABLE IF EXISTS `admin_permissions`;
CREATE TABLE IF NOT EXISTS admin_permissions (
            admin_id      INTEGER NOT NULL,
            permission_id INTEGER NOT NULL,
            PRIMARY KEY (admin_id, permission_id),
            FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
            FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `students`;
CREATE TABLE IF NOT EXISTS students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            first_name   VARCHAR(60)  NOT NULL,
            last_name    VARCHAR(60)  NOT NULL,
            grade        VARCHAR(20)  NOT NULL,
            field        VARCHAR(40)  NOT NULL,
            father_phone VARCHAR(15)  NOT NULL,
            mother_phone VARCHAR(15)  NOT NULL,
            created_at   DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `delays`;
CREATE TABLE IF NOT EXISTS delays (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id  INTEGER NOT NULL,
            jdate       VARCHAR(10) NOT NULL,
            gdate       DATE NOT NULL,
            time        VARCHAR(5)  NOT NULL,
            description VARCHAR(255) NULL,
            created_by  INTEGER NULL,
            created_at  DATETIME NULL,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `absences`;
CREATE TABLE IF NOT EXISTS absences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id   INTEGER NOT NULL,
            jdate        VARCHAR(10) NOT NULL,
            gdate        DATE NOT NULL,
            day_of_week  VARCHAR(20) NOT NULL,
            description  VARCHAR(255) NULL,
            lesson       VARCHAR(120) NULL,
            time         VARCHAR(5) NULL,
            created_by   INTEGER NULL,
            created_at   DATETIME NULL,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `disciplines`;
CREATE TABLE IF NOT EXISTS disciplines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id  INTEGER NOT NULL,
            jdate       VARCHAR(10) NOT NULL,
            gdate       DATE NOT NULL,
            type        VARCHAR(60) NOT NULL,
            description VARCHAR(500) NULL,
            deduction   INTEGER NULL DEFAULT 0,
            created_by  INTEGER NULL,
            created_at  DATETIME NULL,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `dress_violations`;
CREATE TABLE IF NOT EXISTS dress_violations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INTEGER NOT NULL,
            jdate      VARCHAR(10) NOT NULL,
            gdate      DATE NOT NULL,
            type       VARCHAR(30) NOT NULL,
            description VARCHAR(255) NULL,
            created_by INTEGER NULL,
            created_at DATETIME NULL,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username   VARCHAR(100) NOT NULL,
            ip         VARCHAR(45)  NOT NULL,
            success    INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(25)  NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `activity_log`;
CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id   INTEGER NULL,
            action     VARCHAR(120) NOT NULL,
            detail     VARCHAR(500) NULL,
            ip         VARCHAR(45)  NULL,
            created_at VARCHAR(25)  NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `settings`;
CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            skey VARCHAR(60) NOT NULL UNIQUE,
            sval VARCHAR(500) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`skey`,`sval`) VALUES ('school_name','هنرستان شهید رجایی'), ('off_weekdays','5,6'), ('allow_offday_records','0');

SET FOREIGN_KEY_CHECKS = 1;
