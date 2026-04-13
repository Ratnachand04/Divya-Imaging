-- Data dump for table 'users'
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';
SET FOREIGN_KEY_CHECKS=0;
START TRANSACTION;

INSERT INTO `users` (`id`, `username`, `password`, `role`, `is_active`, `created_at`) VALUES
(1, 'manager', '$2y$10$X74s2IIUXL4VQ39I7Xs7keAc6GmW3mbRSzYmjRAUhYsOrOM6Zs0iS', 'manager', 1, '2025-07-23 08:38:01'),
(2, 'receptionist1', '$2y$10$imdgLSoM6DCLJEQeN6BdPO7jsjQpdgd.sfzqjnYW.ytcCd12yGlGy', 'receptionist', 1, '2025-07-23 08:38:01'),
(3, 'accountant', '$2y$10$c7ZLQP442jvm/KN6..eLgOheG0mWLeiLNB820vxU8o0fIAzT8TN1S', 'accountant', 1, '2025-07-23 08:38:01'),
(4, 'writer', '$2y$10$Xa4Heg3pDf61hGV4hq5YUOFJOc3.k/.qc5n3FbcNSpcMjdsnTZSCS', 'writer', 1, '2025-07-23 08:38:02'),
(5, 'superadmin', '$2y$10$imdgLSoM6DCLJEQeN6BdPO7jsjQpdgd.sfzqjnYW.ytcCd12yGlGy', 'superadmin', 1, '2025-07-29 05:13:17'),
(6, 'ratnachand', '$2y$10$SrFvcCTMbYmlvUaDJjLTF.3ncsYCU57hjwQa8/NNpx/zfyB1cn.K2', 'receptionist', 0, '2025-09-20 11:34:32');

COMMIT;
SET FOREIGN_KEY_CHECKS=1;
