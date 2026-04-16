-- Indexes for dumped tables
--

--
-- Indexes for table `bills`
--
ALTER TABLE `bills`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `idx_bills_patient_id` (`patient_id`),
  ADD KEY `idx_bills_status_created` (`bill_status`,`created_at`),
  ADD KEY `idx_bills_receptionist_created` (`receptionist_id`,`created_at`);

--
-- Indexes for table `bill_edit_log`
--
ALTER TABLE `bill_edit_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bill_edit_requests`
--
ALTER TABLE `bill_edit_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bill_items`
--
ALTER TABLE `bill_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bill_items_bill_id` (`bill_id`),
  ADD KEY `idx_bill_items_test_id` (`test_id`),
  ADD KEY `idx_bill_items_status_bill` (`report_status`,`bill_id`),
  ADD KEY `idx_bill_items_status_updated` (`report_status`,`updated_at`),
  ADD KEY `idx_bill_items_item_status` (`item_status`);

--
-- Indexes for table `bill_item_screenings`
--
ALTER TABLE `bill_item_screenings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_bill_item` (`bill_item_id`),
  ADD KEY `bill_item_id` (`bill_item_id`);

--
-- Indexes for table `calendar_events`
--
ALTER TABLE `calendar_events`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `doctor_payout_history`
--
ALTER TABLE `doctor_payout_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `doctor_id` (`doctor_id`),
  ADD KEY `accountant_id` (`accountant_id`);

--
-- Indexes for table `doctor_test_payables`
--
ALTER TABLE `doctor_test_payables`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_doctor_test` (`doctor_id`,`test_id`),
  ADD KEY `test_id` (`test_id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notification_queue`
--
ALTER TABLE `notification_queue`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `patients`
--
UPDATE `patients`
SET `registration_id` = CONCAT('DC', YEAR(`created_at`), LPAD(`id`, 4, '0'))
WHERE `registration_id` IS NULL OR `registration_id` = '';

ALTER TABLE `patients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_patient_registration_id` (`registration_id`);

--
-- Indexes for table `payment_history`
--
ALTER TABLE `payment_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `bill_id` (`bill_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `referral_doctors`
--
ALTER TABLE `referral_doctors`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_audit_log`
--
ALTER TABLE `system_audit_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tests`
--
ALTER TABLE `tests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `writer_report_print_logs`
--
ALTER TABLE `writer_report_print_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bill_item` (`bill_item_id`),
  ADD KEY `idx_printed_at` (`printed_at`);

--
-- Indexes for table `site_messages`
--
ALTER TABLE `site_messages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `error_logs`
--
ALTER TABLE `error_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_error_created` (`created_at`);

--
-- Indexes for table `developer_settings`
--
ALTER TABLE `developer_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_setting_key` (`setting_key`);

--
-- Indexes for table `ip_diagnostics`
--
ALTER TABLE `ip_diagnostics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_diag_created` (`created_at`),
  ADD KEY `idx_diag_type` (`check_type`);

--
-- Indexes for table `app_settings`
--
ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_scope_key` (`setting_scope`,`scope_id`,`setting_key`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bills`
--
ALTER TABLE `bills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6500;

--
-- AUTO_INCREMENT for table `bill_edit_log`
--
ALTER TABLE `bill_edit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT for table `bill_edit_requests`
--
ALTER TABLE `bill_edit_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `bill_items`
--
ALTER TABLE `bill_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12968;

--
-- AUTO_INCREMENT for table `bill_item_screenings`
--
ALTER TABLE `bill_item_screenings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=899;

--
-- AUTO_INCREMENT for table `calendar_events`
--
ALTER TABLE `calendar_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=106;

--
-- AUTO_INCREMENT for table `doctor_payout_history`
--
ALTER TABLE `doctor_payout_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=201;

--
-- AUTO_INCREMENT for table `doctor_test_payables`
--
ALTER TABLE `doctor_test_payables`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=605;

--
-- AUTO_INCREMENT for table `notification_queue`
--
ALTER TABLE `notification_queue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1500,
  MODIFY `registration_id` varchar(12) NOT NULL;

--
-- AUTO_INCREMENT for table `payment_history`
--
ALTER TABLE `payment_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1359;

--
-- AUTO_INCREMENT for table `referral_doctors`
--
ALTER TABLE `referral_doctors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=720;

--
-- AUTO_INCREMENT for table `system_audit_log`
--
ALTER TABLE `system_audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1586;

--
-- AUTO_INCREMENT for table `tests`
--
ALTER TABLE `tests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1433;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `writer_report_print_logs`
--
ALTER TABLE `writer_report_print_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=601;

--
-- AUTO_INCREMENT for table `site_messages`
--
ALTER TABLE `site_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `error_logs`
--
ALTER TABLE `error_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `developer_settings`
--
ALTER TABLE `developer_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `ip_diagnostics`
--
ALTER TABLE `ip_diagnostics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_settings`
--
ALTER TABLE `app_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bill_item_screenings`
--
ALTER TABLE `bill_item_screenings`
  ADD CONSTRAINT `bill_item_screenings_ibfk_1` FOREIGN KEY (`bill_item_id`) REFERENCES `bill_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `doctor_test_payables`
--
ALTER TABLE `doctor_test_payables`
  ADD CONSTRAINT `doctor_test_payables_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `referral_doctors` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `doctor_test_payables_ibfk_2` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE;

-- --------------------------------------------------------

--
-- Update users role enum to include 'platform_admin'
--

ALTER TABLE `users` MODIFY `role` enum('manager','receptionist','accountant','writer','superadmin','platform_admin') NOT NULL;

UPDATE `users` SET `role` = 'platform_admin' WHERE `role` = 'developer';

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
