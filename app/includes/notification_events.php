<?php
/**
 * Domain-specific notification event helpers.
 * Call these from workflows when system events occur.
 */
require_once __DIR__ . '/../core/NotificationManager.php';

final class NotificationEvents
{
    // ── Admin events ────────────────────────────────────────────────────────

    public static function patientRegistered(PDO $pdo, int $patientId, string $patientName, ?int $senderId = null): void
    {
        NotificationManager::notifyAdmins($pdo, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_INFORMATION,
            'title'         => 'New Patient Registered',
            'message'       => "{$patientName} has registered on MedConnect.",
            'action_url'    => '/views/admin/user_management.php',
            'related_table' => 'users',
            'related_id'    => $patientId,
            'icon'          => 'user-plus',
        ]);
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'  => $senderId,
            'type'       => NotificationManager::TYPE_SUCCESS,
            'title'      => 'Welcome to MedConnect',
            'message'    => 'Your account has been created. You can sign in and book a consultation.',
            'action_url' => '/views/patient/dashboard.php',
            'email'      => true,
        ]);
    }

    public static function providerRegistered(PDO $pdo, int $providerId, string $providerName, ?int $senderId = null): void
    {
        NotificationManager::notifyAdmins($pdo, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_WARNING,
            'title'         => 'Provider Approval Required',
            'message'       => "Dr. {$providerName} registered and requires PRC verification.",
            'priority'      => 'high',
            'action_url'    => '/views/admin/doctor_applications.php',
            'related_table' => 'users',
            'related_id'    => $providerId,
        ]);
    }

    public static function bhwRegistered(PDO $pdo, int $bhwId, string $bhwName, ?int $senderId = null): void
    {
        NotificationManager::notifyAdmins($pdo, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_INFORMATION,
            'title'         => 'New BHW Registered',
            'message'       => "{$bhwName} has been added as a Barangay Health Worker.",
            'action_url'    => '/views/admin/user_management.php',
            'related_table' => 'users',
            'related_id'    => $bhwId,
        ]);
    }

    public static function bhwApplicationSubmitted(PDO $pdo, int $applicationId, string $applicantName, int $submittedBy): void
    {
        NotificationManager::notifySuperadmins($pdo, [
            'sender_id'     => $submittedBy,
            'type'          => NotificationManager::TYPE_WARNING,
            'title'         => 'BHW Approval Required',
            'message'       => 'New Barangay Health Worker account requires approval.',
            'priority'      => 'high',
            'action_url'    => '/views/superadmin/bhw_applications.php?tab=pending',
            'related_table' => 'bhw_applications',
            'related_id'    => $applicationId,
            'icon'          => 'user-check',
        ]);
    }

    public static function bhwApplicationApproved(
        PDO $pdo,
        int $applicationId,
        int $bhwUserId,
        string $applicantName,
        int $makerId,
        int $checkerId
    ): void {
        NotificationManager::create($pdo, $makerId, [
            'sender_id'     => $checkerId,
            'type'          => NotificationManager::TYPE_SUCCESS,
            'title'         => 'BHW Account Approved',
            'message'       => "The BHW account for {$applicantName} has been approved and activated.",
            'action_url'    => '/views/admin/bhw_applications.php?tab=active',
            'related_table' => 'bhw_applications',
            'related_id'    => $applicationId,
        ]);
        NotificationManager::notifyBhw($pdo, $bhwUserId, [
            'sender_id'     => $checkerId,
            'type'          => NotificationManager::TYPE_SUCCESS,
            'title'         => 'Welcome to MedConnect',
            'message'       => 'Your Barangay Health Worker account is now active. You may sign in.',
            'action_url'    => '/views/bhw/dashboard.php',
            'related_table' => 'users',
            'related_id'    => $bhwUserId,
        ]);
    }

    public static function bhwApplicationRejected(
        PDO $pdo,
        int $applicationId,
        string $applicantName,
        int $makerId,
        int $checkerId,
        string $reason
    ): void {
        NotificationManager::create($pdo, $makerId, [
            'sender_id'     => $checkerId,
            'type'          => NotificationManager::TYPE_WARNING,
            'title'         => 'BHW Application Rejected',
            'message'       => "The BHW application for {$applicantName} was rejected. Reason: {$reason}",
            'action_url'    => '/views/admin/bhw_applications.php',
            'related_table' => 'bhw_applications',
            'related_id'    => $applicationId,
        ]);
    }

    public static function bhwApplicationDocsRequested(
        PDO $pdo,
        int $applicationId,
        string $applicantName,
        int $makerId,
        int $checkerId,
        string $note
    ): void {
        NotificationManager::create($pdo, $makerId, [
            'sender_id'     => $checkerId,
            'type'          => NotificationManager::TYPE_INFORMATION,
            'title'         => 'Additional BHW Documents Required',
            'message'       => "Additional documents are required for {$applicantName}. {$note}",
            'action_url'    => '/views/admin/bhw_applications.php',
            'related_table' => 'bhw_applications',
            'related_id'    => $applicationId,
        ]);
    }

    public static function doctorApplicationSubmitted(PDO $pdo, int $applicationId, string $doctorName, int $submittedBy): void
    {
        NotificationManager::notifySuperadmins($pdo, [
            'sender_id'     => $submittedBy,
            'type'          => NotificationManager::TYPE_WARNING,
            'title'         => 'Doctor Approval Required',
            'message'       => 'New Doctor Account requires approval.',
            'priority'      => 'high',
            'action_url'    => '/views/superadmin/doctor_applications.php?tab=pending',
            'related_table' => 'doctor_applications',
            'related_id'    => $applicationId,
            'icon'          => 'user-check',
        ]);
    }

    public static function doctorApplicationApproved(
        PDO $pdo,
        int $applicationId,
        int $providerUserId,
        string $doctorName,
        int $makerId,
        int $checkerId
    ): void {
        NotificationManager::create($pdo, $makerId, [
            'sender_id'     => $checkerId,
            'type'          => NotificationManager::TYPE_SUCCESS,
            'title'         => 'Doctor Account Approved',
            'message'       => 'The Doctor Account has been approved and activated.',
            'action_url'    => '/views/admin/doctor_applications.php?tab=active',
            'related_table' => 'doctor_applications',
            'related_id'    => $applicationId,
        ]);
        NotificationManager::notifyProvider($pdo, $providerUserId, [
            'sender_id'     => $checkerId,
            'type'          => NotificationManager::TYPE_SUCCESS,
            'title'         => 'Account Activated',
            'message'       => 'Your MEDCONNECT account has been activated. You may now log in.',
            'action_url'    => '/views/provider/dashboard.php',
            'related_table' => 'users',
            'related_id'    => $providerUserId,
            'email'         => true,
        ]);
    }

    public static function doctorApplicationRejected(
        PDO $pdo,
        int $applicationId,
        string $doctorName,
        int $makerId,
        int $checkerId,
        string $reason,
        string $applicantEmail = ''
    ): void {
        NotificationManager::create($pdo, $makerId, [
            'sender_id'     => $checkerId,
            'type'          => NotificationManager::TYPE_WARNING,
            'title'         => 'Doctor Application Rejected',
            'message'       => "The Doctor Account application for Dr. {$doctorName} was rejected. Reason: {$reason}",
            'action_url'    => '/views/admin/doctor_applications.php',
            'related_table' => 'doctor_applications',
            'related_id'    => $applicationId,
        ]);
    }

    public static function doctorApplicationDocsRequested(
        PDO $pdo,
        int $applicationId,
        string $doctorName,
        int $makerId,
        int $checkerId,
        string $note
    ): void {
        NotificationManager::create($pdo, $makerId, [
            'sender_id'     => $checkerId,
            'type'          => NotificationManager::TYPE_INFORMATION,
            'title'         => 'Additional Doctor Documents Required',
            'message'       => "Additional documents are required for Dr. {$doctorName}. {$note}",
            'action_url'    => '/views/admin/doctor_applications.php',
            'related_table' => 'doctor_applications',
            'related_id'    => $applicationId,
        ]);
    }

    public static function appointmentCreated(PDO $pdo, int $consultationId, int $patientId, int $providerId, string $date, ?int $senderId = null): void
    {
        NotificationManager::notifyAdmins($pdo, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'New Appointment Created',
            'message'       => "Appointment scheduled for {$date}.",
            'action_url'    => '/views/admin/live_consultation_monitor.php?tab=queue',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'Appointment Confirmed',
            'message'       => "Your appointment is scheduled for {$date}.",
            'action_url'    => '/views/patient/consultations.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
            'email'         => true,
        ]);
        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'New Appointment Request',
            'message'       => "A new appointment is scheduled for {$date}.",
            'action_url'    => '/views/provider/queue.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
    }

    public static function appointmentCancelled(PDO $pdo, int $consultationId, int $patientId, int $providerId, ?int $senderId = null): void
    {
        NotificationManager::notifyAdmins($pdo, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'Appointment Cancelled',
            'message'       => 'An appointment has been cancelled.',
            'action_url'    => '/views/admin/live_consultation_monitor.php?tab=queue',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_WARNING,
            'title'         => 'Appointment Cancelled',
            'message'       => 'Your appointment has been cancelled.',
            'action_url'    => '/views/patient/consultations.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'Appointment Cancelled',
            'message'       => 'A patient appointment has been cancelled.',
            'action_url'    => '/views/provider/queue.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
        NotificationManager::notifyBhwForPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'Consultation Cancelled',
            'message'       => 'A patient consultation has been cancelled.',
            'action_url'    => '/views/bhw/consultations/index.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
    }

    public static function appointmentRescheduled(PDO $pdo, int $consultationId, int $patientId, int $providerId, string $newDate, ?int $senderId = null, ?string $oldDate = null): void
    {
        $message = $oldDate !== null && $oldDate !== ''
            ? "Your appointment moved from {$oldDate} to {$newDate}."
            : "Your appointment has been rescheduled to {$newDate}.";

        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'Appointment Rescheduled',
            'message'       => $message,
            'action_url'    => '/views/patient/consultations.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
            'email'         => true,
        ]);
        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'Appointment Rescheduled',
            'message'       => $oldDate
                ? "Patient confirmed reschedule from {$oldDate} to {$newDate}."
                : "An appointment has been rescheduled to {$newDate}.",
            'action_url'    => '/views/provider/queue.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
        NotificationManager::notifyBhwForPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'Consultation Rescheduled',
            'message'       => $oldDate
                ? "Patient consultation moved from {$oldDate} to {$newDate}."
                : "Patient consultation rescheduled to {$newDate}.",
            'action_url'    => '/views/bhw/consultations/index.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
    }

    public static function appointmentRescheduleRequested(
        PDO $pdo,
        int $consultationId,
        int $patientId,
        int $providerId,
        string $oldDate,
        string $newDate,
        string $reason,
        ?int $senderId = null
    ): void {
        $message = "Your doctor requested to move your appointment from {$oldDate} to {$newDate}. "
            . 'Please review and confirm in My Sessions. Reason: ' . $reason;

        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'Reschedule Request — Action Required',
            'message'       => $message,
            'action_url'    => '/views/patient/consultations.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
            'email'         => true,
        ]);
        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'Reschedule Request Sent',
            'message'       => "Waiting for patient to confirm moving from {$oldDate} to {$newDate}.",
            'action_url'    => '/views/provider/schedule.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
    }

    public static function appointmentRescheduleDeclined(
        PDO $pdo,
        int $consultationId,
        int $patientId,
        int $providerId,
        ?int $senderId = null
    ): void {
        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_WARNING,
            'title'         => 'Reschedule Declined',
            'message'       => 'The patient declined your reschedule request. The original appointment time remains.',
            'action_url'    => '/views/provider/schedule.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'Original Appointment Kept',
            'message'       => 'You declined the reschedule. Your original appointment time is unchanged.',
            'action_url'    => '/views/patient/consultations.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
    }

    public static function referralCreated(PDO $pdo, int $referralId, int $patientId, ?int $providerId, ?int $senderId = null): void
    {
        NotificationManager::notifyAdmins($pdo, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_REFERRAL,
            'title'         => 'Referral Created',
            'message'       => 'A new patient referral has been submitted.',
            'action_url'    => '/views/admin/live_consultation_monitor.php?tab=queue',
            'related_table' => 'digital_referrals',
            'related_id'    => $referralId,
        ]);
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_REFERRAL,
            'title'         => 'Referral Created',
            'message'       => 'A referral has been created for your care.',
            'action_url'    => '/views/patient/dashboard.php#action-items',
            'related_table' => 'digital_referrals',
            'related_id'    => $referralId,
        ]);
        if ($providerId) {
            NotificationManager::notifyProvider($pdo, $providerId, [
                'sender_id'     => $senderId,
                'type'          => NotificationManager::TYPE_REFERRAL,
                'title'         => 'New Patient Referral',
                'message'       => 'A new referral has been submitted for your review. Check Active Triage for emergency cases.',
                'action_url'    => '/views/provider/triage.php',
                'related_table' => 'digital_referrals',
                'related_id'    => $referralId,
            ]);
        }
        NotificationManager::notifyBhwForPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_REFERRAL,
            'title'         => 'Referral Submitted',
            'message'       => 'A referral has been submitted for your patient.',
            'action_url'    => '/views/bhw/referral/status.php',
            'related_table' => 'digital_referrals',
            'related_id'    => $referralId,
        ]);
    }

    public static function referralStatusChanged(PDO $pdo, int $referralId, int $patientId, string $status, ?int $providerId = null, ?int $senderId = null): void
    {
        $title = match ($status) {
            'accepted'  => 'Referral Accepted',
            'rejected'  => 'Referral Rejected',
            'completed' => 'Referral Completed',
            default     => 'Referral Status Updated',
        };
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_REFERRAL,
            'title'         => $title,
            'message'       => "Your referral status is now: {$status}.",
            'action_url'    => '/views/patient/dashboard.php#action-items',
            'related_table' => 'digital_referrals',
            'related_id'    => $referralId,
            'email'         => $status === 'accepted',
        ]);
        NotificationManager::notifyBhwForPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_REFERRAL,
            'title'         => $title,
            'message'       => "Referral for your patient is now: {$status}.",
            'action_url'    => '/views/bhw/referral/status.php',
            'related_table' => 'digital_referrals',
            'related_id'    => $referralId,
        ]);
    }

    public static function consultationScheduled(PDO $pdo, int $consultationId, int $patientId, int $providerId, string $when, ?int $senderId = null): void
    {
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_CONSULTATION,
            'title'         => 'Video Consultation Scheduled',
            'message'       => "Your video consultation is scheduled for {$when}.",
            'action_url'    => '/views/patient/consultations.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
            'email'         => true,
        ]);
        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_CONSULTATION,
            'title'         => 'Video Consultation Scheduled',
            'message'       => "Video consultation scheduled for {$when}.",
            'action_url'    => '/views/provider/queue.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
        NotificationManager::notifyBhwForPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_CONSULTATION,
            'title'         => 'Provider Scheduled Consultation',
            'message'       => "Consultation scheduled for {$when}.",
            'action_url'    => '/views/bhw/consultations/index.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
    }

    public static function consultationCompleted(PDO $pdo, int $consultationId, int $patientId, int $providerId, ?int $senderId = null, ?string $providerName = null, ?string $finalCaseLevel = null): void
    {
        require_once __DIR__ . '/patient_consultation_records.php';
        $name = trim((string) ($providerName ?? 'your healthcare provider'));
        if ($name !== '' && stripos($name, 'dr.') !== 0) {
            $name = 'Dr. ' . $name;
        }
        $detailUrl = patient_health_files_url($consultationId);
        $level = trim((string) ($finalCaseLevel ?? ''));
        $message = "Your consultation with {$name} has been completed. Your health file and care plan are now available in My Health.";
        if ($level !== '') {
            $message = "Your consultation with {$name} has been completed. Final case level: {$level}. View your health file in My Health.";
        }

        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_SUCCESS,
            'title'         => 'Consultation completed',
            'message'       => $message,
            'action_url'    => $detailUrl,
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_CONSULTATION,
            'title'         => 'Follow-Up Required',
            'message'       => 'Consultation completed. Schedule follow-up if needed.',
            'action_url'    => '/views/provider/records.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
        NotificationManager::notifyBhwForPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_CONSULTATION,
            'title'         => 'Consultation Completed',
            'message'       => 'Patient consultation has been completed.',
            'action_url'    => '/views/bhw/consultations/index.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
    }

    public static function patientJoinedWaitingRoom(PDO $pdo, int $consultationId, int $providerId, string $patientName, ?int $senderId = null): void
    {
        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_CONSULTATION,
            'title'         => 'Patient in Waiting Room',
            'message'       => "{$patientName} has joined the waiting room.",
            'priority'      => 'high',
            'action_url'    => '/views/provider/queue.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
    }

    public static function consultationStarting(PDO $pdo, int $consultationId, int $patientId, ?int $senderId = null): void
    {
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_REMINDER,
            'title'         => 'Video Consultation Starting',
            'message'       => 'Your video consultation is starting now. Please join the session.',
            'priority'      => 'high',
            'action_url'    => '/views/patient/consultations.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
    }

    public static function highRiskPatient(PDO $pdo, int $patientId, string $patientName, string $reason, ?int $senderId = null): void
    {
        NotificationManager::notifyAdmins($pdo, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_EMERGENCY,
            'title'         => 'High-Risk Patient Detected',
            'message'       => "{$patientName}: {$reason}",
            'priority'      => 'emergency',
            'action_url'    => '/views/admin/live_consultation_monitor.php?tab=queue',
            'related_table' => 'triage_results',
            'related_id'    => $patientId,
        ]);
        NotificationManager::notifyBhwForPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_EMERGENCY,
            'title'         => 'High-Risk Patient Detected',
            'message'       => "{$patientName}: {$reason}",
            'priority'      => 'emergency',
            'action_url'    => '/views/bhw/patients/list.php',
            'related_table' => 'triage_results',
            'related_id'    => $patientId,
        ]);
    }

    public static function aiTriageCompleted(PDO $pdo, int $patientId, string $urgency, ?int $senderId = null): void
    {
        NotificationManager::notifyAdmins($pdo, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_MEDICAL,
            'title'         => 'AI Triage Completed',
            'message'       => "Triage assessment completed. Urgency: {$urgency}.",
            'action_url'    => '/views/admin/live_consultation_monitor.php?tab=queue',
            'related_table' => 'triage_results',
            'related_id'    => $patientId,
        ]);
        if (in_array(strtolower($urgency), ['urgent', 'emergency', '1', '2'], true)) {
            self::highRiskPatient($pdo, $patientId, 'Patient', "AI triage urgency: {$urgency}", $senderId);
        }
    }

    public static function medicalRecordUpdated(PDO $pdo, int $patientId, ?int $providerId = null, ?int $senderId = null): void
    {
        NotificationManager::notifyAdmins($pdo, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_MEDICAL,
            'title'         => 'Medical Record Updated',
            'message'       => 'A patient medical record has been updated.',
            'action_url'    => '/views/admin/live_consultation_monitor.php?tab=queue',
            'related_table' => 'patient_registrations',
            'related_id'    => $patientId,
        ]);
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_MEDICAL,
            'title'         => 'Medical Record Updated',
            'message'       => 'Your medical record has been updated.',
            'action_url'    => '/views/patient/my_health.php?tab=files',
            'related_table' => 'patient_registrations',
            'related_id'    => $patientId,
        ]);
        if ($providerId) {
            NotificationManager::notifyProvider($pdo, $providerId, [
                'sender_id'     => $senderId,
                'type'          => NotificationManager::TYPE_MEDICAL,
                'title'         => 'Medical Record Updated',
                'message'       => 'Patient medical record has been updated.',
                'action_url'    => '/views/provider/medical_records.php',
                'related_table' => 'patient_registrations',
                'related_id'    => $patientId,
            ]);
        }
    }

    public static function prescriptionAvailable(PDO $pdo, int $patientId, int $providerId, ?int $senderId = null, ?int $consultationId = null): void
    {
        require_once __DIR__ . '/patient_consultation_records.php';
        $actionUrl = $consultationId
            ? patient_consultation_detail_url($consultationId)
            : '/views/patient/my_health.php?tab=files';

        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_MEDICAL,
            'title'         => 'Prescription Available',
            'message'       => 'A new prescription is available for you.',
            'action_url'    => $actionUrl,
            'related_table' => $consultationId ? 'consultations' : null,
            'related_id'    => $consultationId,
        ]);
        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'  => $senderId,
            'type'       => NotificationManager::TYPE_MEDICAL,
            'title'      => 'Prescription Issued',
            'message'    => 'Prescription has been issued to patient.',
            'action_url' => '/views/provider/records.php',
        ]);
    }

    public static function gisHotspotDetected(PDO $pdo, string $location, ?int $senderId = null): void
    {
        NotificationManager::notifyAdmins($pdo, [
            'sender_id'  => $senderId,
            'type'       => NotificationManager::TYPE_GIS,
            'title'      => 'GIS Hotspot Detected',
            'message'    => "Health hotspot detected in {$location}.",
            'priority'   => 'high',
            'action_url' => '/views/admin/gis_dashboard.php',
        ]);
        NotificationManager::notifyRole($pdo, 'bhw', [
            'sender_id'  => $senderId,
            'type'       => NotificationManager::TYPE_GIS,
            'title'      => 'GIS Hotspot Alert',
            'message'    => "Health activity hotspot in {$location}.",
            'priority'   => 'high',
            'action_url' => '/views/bhw/dashboard.php',
        ]);
    }

    public static function loginFailed(PDO $pdo, int $userId, string $email, string $role = 'patient'): void
    {
        NotificationManager::notifyAdmins($pdo, [
            'type'       => NotificationManager::TYPE_SECURITY,
            'title'      => 'Failed Login Attempt',
            'message'    => "Failed login for {$email}.",
            'priority'   => 'high',
            'action_url' => '/views/admin/audit_logs.php',
        ]);
        NotificationManager::create($pdo, $userId, [
            'receiver_role' => $role,
            'type'          => NotificationManager::TYPE_CRITICAL,
            'title'         => 'Failed Login Attempt',
            'message'       => 'A failed login attempt was detected on your account. If this was not you, change your password immediately.',
            'priority'      => 'critical',
            'icon'          => 'alert-octagon',
            'action_url'    => NotificationManager::dashboardPathForRole($role),
        ]);
    }

    public static function loginSuccess(PDO $pdo, int $userId, string $role): void
    {
        // First login reminder for patients
        if ($role !== 'patient') {
            return;
        }
        try {
            $stmt = $pdo->prepare('SELECT login_count FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $count = (int) $stmt->fetchColumn();
            if ($count <= 2) {
                NotificationManager::notifyPatient($pdo, $userId, [
                    'type'       => NotificationManager::TYPE_REMINDER,
                    'title'      => 'Complete Your Profile',
                    'message'    => 'Welcome back! Complete your medical profile for better care.',
                    'action_url' => '/views/patient/profile.php',
                ]);
            }
        } catch (PDOException $e) { /* non-fatal */ }
    }

    public static function passwordChanged(PDO $pdo, int $userId, string $role): void
    {
        NotificationManager::create($pdo, $userId, [
            'type'       => NotificationManager::TYPE_SECURITY,
            'title'      => 'Password Changed',
            'message'    => 'Your password was changed successfully.',
            'action_url' => NotificationManager::dashboardPathForRole($role),
        ]);
        NotificationManager::notifyAdmins($pdo, [
            'type'       => NotificationManager::TYPE_SECURITY,
            'title'      => 'User Password Reset',
            'message'    => 'A user has changed their password.',
            'action_url' => '/views/admin/audit_logs.php',
        ]);
    }

    public static function passwordResetRequested(PDO $pdo, int $userId, string $email): void
    {
        NotificationManager::notifyAdmins($pdo, [
            'type'       => NotificationManager::TYPE_SECURITY,
            'title'      => 'Password Reset Requested',
            'message'    => "Password reset requested for {$email}.",
            'action_url' => '/views/admin/audit_logs.php',
        ]);
    }

    public static function backupCompleted(PDO $pdo, bool $success): void
    {
        NotificationManager::notifyAdmins($pdo, [
            'type'     => $success ? NotificationManager::TYPE_SUCCESS : NotificationManager::TYPE_CRITICAL,
            'title'    => $success ? 'Database Backup Completed' : 'Database Backup Failed',
            'message'  => $success ? 'Scheduled database backup completed successfully.' : 'Database backup failed. Review system logs.',
            'priority' => $success ? 'normal' : 'critical',
            'action_url' => '/views/admin/dashboard.php',
        ]);
    }

    public static function systemError(PDO $pdo, string $message): void
    {
        NotificationManager::notifyAdmins($pdo, [
            'type'     => NotificationManager::TYPE_CRITICAL,
            'title'    => 'System Error',
            'message'  => $message,
            'priority' => 'critical',
            'action_url' => '/views/admin/audit_logs.php',
        ]);
    }

    public static function patientMessage(PDO $pdo, int $providerId, int $patientId, string $patientName, ?int $senderId = null, ?int $consultationId = null): void
    {
        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'     => $senderId ?? $patientId,
            'type'          => NotificationManager::TYPE_INFORMATION,
            'title'         => 'New Patient Message',
            'message'       => "{$patientName} sent you a message.",
            'action_url'    => '/views/provider/messages.php',
            'related_table' => $consultationId ? 'consultations' : null,
            'related_id'    => $consultationId,
            'icon'          => 'message-circle',
        ]);
    }

    public static function providerMessage(PDO $pdo, int $patientId, int $providerId, string $providerName, ?int $senderId = null, ?int $consultationId = null): void
    {
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId ?? $providerId,
            'type'          => NotificationManager::TYPE_INFORMATION,
            'title'         => 'New Message from Your Provider',
            'message'       => "{$providerName} sent you a message.",
            'action_url'    => '/views/patient/messages.php',
            'related_table' => $consultationId ? 'consultations' : null,
            'related_id'    => $consultationId,
            'icon'          => 'message-circle',
        ]);
    }

    public static function bhwPatientRegistered(PDO $pdo, int $bhwId, int $patientId, string $patientName, ?int $senderId = null): void
    {
        NotificationManager::notifyBhw($pdo, $bhwId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_SUCCESS,
            'title'         => 'Patient Registered Successfully',
            'message'       => "{$patientName} has been registered in your barangay.",
            'action_url'    => '/views/bhw/patients/list.php',
            'related_table' => 'users',
            'related_id'    => $patientId,
        ]);
    }

    public static function followUpScheduled(PDO $pdo, int $patientId, string $date, ?int $providerId = null, ?int $senderId = null, bool $email = true): void
    {
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'  => $senderId,
            'type'       => NotificationManager::TYPE_REMINDER,
            'title'      => 'Follow-Up Scheduled',
            'message'    => "Your follow-up is scheduled for {$date}.",
            'action_url' => '/views/patient/dashboard.php#action-items',
            'email'      => $email,
        ]);
        if ($providerId) {
            NotificationManager::notifyProvider($pdo, $providerId, [
                'sender_id'  => $senderId,
                'type'       => NotificationManager::TYPE_REMINDER,
                'title'      => 'Follow-Up Scheduled',
                'message'    => "Follow-up scheduled for {$date}.",
                'action_url' => '/views/provider/schedule.php',
            ]);
        }
    }

    public static function emergencyConsultation(PDO $pdo, int $consultationId, int $patientId, int $providerId, ?int $senderId = null): void
    {
        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_EMERGENCY,
            'title'         => 'Emergency Patient Assigned',
            'message'       => 'An emergency consultation has been assigned to you.',
            'priority'      => 'emergency',
            'action_url'    => '/views/provider/queue.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
            'email'         => true,
        ]);
        NotificationManager::notifyAdmins($pdo, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_EMERGENCY,
            'title'         => 'Emergency Consultation Requested',
            'message'       => 'An emergency consultation has been requested.',
            'priority'      => 'emergency',
            'action_url'    => '/views/admin/live_consultation_monitor.php?tab=queue',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
        NotificationManager::notifyBhwForPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_EMERGENCY,
            'title'         => 'Emergency Patient Assigned',
            'message'       => 'Emergency consultation assigned for your patient.',
            'priority'      => 'emergency',
            'action_url'    => '/views/bhw/consultations/index.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
    }

    public static function providerVerified(PDO $pdo, int $providerId, bool $approved, ?int $senderId = null): void
    {
        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'  => $senderId,
            'type'       => $approved ? NotificationManager::TYPE_SUCCESS : NotificationManager::TYPE_WARNING,
            'title'      => $approved ? 'Account Approved' : 'Account Rejected',
            'message'    => $approved
                ? 'Your provider account has been verified. You can now sign in.'
                : 'Your provider account verification was rejected. Contact admin.',
            'action_url' => '/views/provider/dashboard.php',
            'email'      => true,
        ]);
    }

    /**
     * Notify all active patients when a provider publishes today's bookable slots.
     */
    public static function providerScheduleAvailable(
        PDO $pdo,
        int $providerId,
        string $providerName,
        string $day,
        string $startTime,
        string $endTime,
        int $slotsCreated
    ): int {
        if ($slotsCreated <= 0) {
            return 0;
        }

        $providerName = trim($providerName) !== '' ? trim($providerName) : 'A healthcare provider';
        $startLabel = date('g:i A', strtotime($startTime));
        $endLabel   = date('g:i A', strtotime($endTime));
        $todayLabel = date('M j, Y');
        $slotWord   = $slotsCreated === 1 ? 'slot' : 'slots';

        try {
            $dedupe = $pdo->prepare("
                SELECT 1 FROM notifications
                WHERE sender_id = ?
                  AND related_table = 'provider_schedules'
                  AND related_id = ?
                  AND type = ?
                  AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                LIMIT 1
            ");
            $dedupe->execute([
                $providerId,
                $providerId,
                NotificationManager::TYPE_APPOINTMENT,
            ]);
            if ($dedupe->fetchColumn()) {
                return 0;
            }
        } catch (PDOException $e) {
            error_log('providerScheduleAvailable dedupe: ' . $e->getMessage());
        }

        return NotificationManager::notifyRole($pdo, 'patient', [
            'sender_id'     => $providerId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'New Appointment Slots Available',
            'message'       => "{$providerName} opened {$slotsCreated} {$slotWord} for today ({$day}, {$todayLabel}) from {$startLabel} to {$endLabel}. Book your consultation now.",
            'action_url'    => '/views/patient/triage.php',
            'related_table' => 'provider_schedules',
            'related_id'    => $providerId,
            'priority'      => 'normal',
            'icon'          => 'calendar',
        ]);
    }

    /**
     * Email + in-app notice when a waiting NON-URGENT patient can book a slot.
     */
    public static function consultationSlotAvailable(
        PDO $pdo,
        int $patientId,
        int $providerId,
        string $providerName,
        int $waitlistId,
        int $triageId
    ): ?int {
        if ($patientId <= 0 || $waitlistId <= 0) {
            return null;
        }

        $providerName = trim($providerName) !== '' ? trim($providerName) : 'A healthcare provider';
        $bookUrl = '/views/patient/triage.php' . ($triageId > 0 ? ('?triage_id=' . $triageId) : '');

        try {
            $dedupe = $pdo->prepare("
                SELECT id FROM notifications
                WHERE user_id = ?
                  AND related_table = 'patient_slot_waitlist'
                  AND related_id = ?
                  AND type = ?
                  AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                LIMIT 1
            ");
            $dedupe->execute([
                $patientId,
                $waitlistId,
                NotificationManager::TYPE_APPOINTMENT,
            ]);
            $existingId = (int) ($dedupe->fetchColumn() ?: 0);
            if ($existingId > 0) {
                return $existingId;
            }
        } catch (PDOException $e) {
            error_log('consultationSlotAvailable dedupe: ' . $e->getMessage());
        }

        return NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $providerId > 0 ? $providerId : null,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'Consultation Slot Available',
            'message'       => "A consultation slot is now available with {$providerName}. Your patient complaint was classified as NON-URGENT and you were previously waiting for provider availability. Please log in to medConnect to view and book an available consultation slot. Provider: {$providerName}.",
            'action_url'    => $bookUrl,
            'related_table' => 'patient_slot_waitlist',
            'related_id'    => $waitlistId,
            'priority'      => 'high',
            'icon'          => 'calendar',
            'email'         => true,
        ]);
    }

    public static function aiSelfCareReviewRequired(
        PDO $pdo,
        int $providerId,
        int $patientId,
        string $patientName,
        int $triageId,
        ?int $senderId = null
    ): void {
        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'     => $senderId ?? $patientId,
            'type'          => NotificationManager::TYPE_MEDICAL,
            'title'         => 'AI Self-Care Review Required',
            'message'       => "{$patientName} has a non-urgent case with AI-generated guidance awaiting your review.",
            'action_url'    => '/views/provider/triage.php',
            'related_table' => 'triage_results',
            'related_id'    => $triageId,
            'priority'      => 'high',
            'icon'          => 'clipboard',
        ]);
    }

    public static function careTipsApprovedForPatient(
        PDO $pdo,
        int $patientId,
        int $providerId,
        string $providerName,
        int $triageId,
        ?int $senderId = null
    ): void {
        $providerName = trim($providerName) !== '' ? trim($providerName) : 'your healthcare provider';
        $complaint = '';
        if ($triageId > 0) {
            try {
                $st = $pdo->prepare('SELECT chief_complaint FROM triage_results WHERE id = ? AND patient_id = ? LIMIT 1');
                $st->execute([$triageId, $patientId]);
                $complaint = trim((string) ($st->fetchColumn() ?: ''));
            } catch (PDOException $e) {
                $complaint = '';
            }
        }
        $message = "Your patient complaint has been reviewed by {$providerName}. Your provider has also reviewed your care guidance.";
        if ($complaint !== '') {
            $message .= ' Patient Complaint: ' . $complaint . '.';
        }
        $message .= ' Triage Classification: Non-Urgent. Reviewed By: ' . $providerName . '. Status: Reviewed. Please log in to your medConnect account to view the reviewed information and continue with your consultation if applicable.';

        try {
            $dedupe = $pdo->prepare("
                SELECT id FROM notifications
                WHERE user_id = ?
                  AND related_table = 'triage_results'
                  AND related_id = ?
                  AND title = ?
                  AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                LIMIT 1
            ");
            $dedupe->execute([
                $patientId,
                $triageId,
                'Your Patient Complaint Has Been Reviewed',
            ]);
            if ((int) ($dedupe->fetchColumn() ?: 0) > 0) {
                return;
            }
        } catch (PDOException $e) {
            error_log('careTipsApprovedForPatient dedupe: ' . $e->getMessage());
        }

        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId ?? $providerId,
            'type'          => NotificationManager::TYPE_SUCCESS,
            'title'         => 'Your Patient Complaint Has Been Reviewed',
            'message'       => $message,
            'action_url'    => '/views/patient/my_health.php?tab=care-tips',
            'related_table' => 'triage_results',
            'related_id'    => $triageId,
            'icon'          => 'heart',
            'email'         => true,
        ]);
    }

    public static function doctorFinalTriageForPatient(
        PDO $pdo,
        int $patientId,
        int $providerId,
        int $consultationId,
        int $triageId,
        string $finalBucket,
        string $finalLabel,
        string $aiLabel = '',
        string $clinicalReason = '',
        ?int $senderId = null
    ): void {
        $bucket = strtolower(str_replace(['-', ' '], '_', trim($finalBucket)));
        $aiNote = $aiLabel !== '' ? " Preliminary AI assessment was {$aiLabel}." : '';
        $reasonNote = $clinicalReason !== '' ? ' Clinical reason: ' . $clinicalReason : '';
        $relatedId = $consultationId > 0 ? $consultationId : $triageId;
        $relatedTable = $consultationId > 0 ? 'consultations' : 'triage_results';

        if ($bucket === 'emergency') {
            NotificationManager::notifyPatient($pdo, $patientId, [
                'sender_id'     => $senderId ?? $providerId,
                'type'          => NotificationManager::TYPE_EMERGENCY,
                'title'         => 'Emergency — seek hospital care now',
                'message'       => 'Your doctor classified this consultation as an EMERGENCY. Please seek immediate in-person medical attention. You may continue the live consultation while arranging transfer.'
                    . $aiNote . $reasonNote,
                'priority'      => 'emergency',
                'action_url'    => '/views/patient/consultations.php',
                'related_table' => $relatedTable,
                'related_id'    => $relatedId,
                'email'         => true,
            ]);
            return;
        }

        if ($bucket === 'urgent') {
            NotificationManager::notifyPatient($pdo, $patientId, [
                'sender_id'     => $senderId ?? $providerId,
                'type'          => NotificationManager::TYPE_WARNING,
                'title'         => 'Final triage result: URGENT',
                'message'       => 'Your doctor finalized this consultation as URGENT. Follow your care plan and seek care promptly if symptoms worsen.'
                    . $aiNote,
                'priority'      => 'high',
                'action_url'    => '/views/patient/consultations.php',
                'related_table' => $relatedTable,
                'related_id'    => $relatedId,
                'email'         => true,
            ]);
            return;
        }

        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId ?? $providerId,
            'type'          => NotificationManager::TYPE_INFORMATION,
            'title'         => 'Final triage result: NON-URGENT',
            'message'       => 'Your doctor finalized this consultation as NON-URGENT. Continue your consultation and follow-up plan as advised.'
                . $aiNote,
            'action_url'    => '/views/patient/consultations.php',
            'related_table' => $relatedTable,
            'related_id'    => $relatedId,
        ]);
    }

    public static function doctorEmergencyOverrideForPatient(
        PDO $pdo,
        int $patientId,
        int $providerId,
        int $triageId,
        string $aiLabel = '',
        ?int $senderId = null
    ): void {
        self::doctorFinalTriageForPatient(
            $pdo,
            $patientId,
            $providerId,
            0,
            $triageId,
            'emergency',
            'EMERGENCY',
            $aiLabel,
            '',
            $senderId
        );
    }

    public static function careTipsReviewUpdatedForPatient(
        PDO $pdo,
        int $patientId,
        int $providerId,
        bool $approved,
        int $triageId,
        ?int $senderId = null
    ): void {
        if ($approved) {
            return;
        }
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId ?? $providerId,
            'type'          => NotificationManager::TYPE_INFORMATION,
            'title'         => 'Care Tips Update',
            'message'       => 'Your provider reviewed your case. AI self-care tips were not released — please book a consultation if you need further help.',
            'action_url'    => '/views/patient/triage.php',
            'related_table' => 'triage_results',
            'related_id'    => $triageId,
        ]);
    }

    // ── Urgent Follow-up Workflow ───────────────────────────────────────────

    public static function urgentFollowupCaseCreated(
        PDO $pdo,
        int $providerId,
        int $patientId,
        string $patientName,
        int $caseId,
        string $previousConsultDate,
        string $previousComplaint,
        string $updatedComplaint,
        string $triageClassification,
        float $confidence,
        bool $canStartImmediately,
        bool $wasReassigned
    ): void {
        $confidenceLabel = number_format($confidence, 0) . '%';
        $timestamp = date('M j, Y g:i A');
        $reassignNote = $wasReassigned ? ' (reassigned — original doctor unavailable)' : '';
        $startNote = $canStartImmediately
            ? ' You may start a video consultation when ready.'
            : ' Patient is in your Urgent Follow-up Queue — accept when your current consultation is complete.';

        $message = implode("\n", [
            "Patient: {$patientName}{$reassignNote}",
            "Previous consultation: {$previousConsultDate}",
            "Previous chief complaint: {$previousComplaint}",
            "Updated chief complaint: {$updatedComplaint}",
            "AI triage: {$triageClassification}",
            "AI confidence: {$confidenceLabel}",
            "Submitted: {$timestamp}",
        ]) . $startNote;

        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'     => $patientId,
            'type'          => NotificationManager::TYPE_EMERGENCY,
            'title'         => 'Urgent Follow-up Waiting',
            'message'       => $message,
            'priority'      => 'critical',
            'action_url'    => '/views/provider/queue.php#urgent-followup-queue',
            'related_table' => 'urgent_followup_cases',
            'related_id'    => $caseId,
            'icon'          => 'alert-triangle',
        ]);
    }

    public static function urgentFollowupEmergencyReferral(
        PDO $pdo,
        int $providerId,
        int $patientId,
        string $patientName,
        int $caseId,
        string $previousConsultDate,
        string $previousComplaint,
        string $updatedComplaint,
        string $triageClassification,
        float $confidence
    ): void {
        $confidenceLabel = number_format($confidence, 0) . '%';
        $timestamp = date('M j, Y g:i A');

        $message = implode("\n", [
            "Patient: {$patientName}",
            "Previous consultation: {$previousConsultDate}",
            "Previous chief complaint: {$previousComplaint}",
            "Updated chief complaint: {$updatedComplaint}",
            "AI triage: {$triageClassification}",
            "AI confidence: {$confidenceLabel}",
            "Submitted: {$timestamp}",
            'Patient has been advised to proceed to the nearest Emergency Department or call local emergency services.',
        ]);

        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'     => $patientId,
            'type'          => NotificationManager::TYPE_EMERGENCY,
            'title'         => 'Emergency Referral — Follow-up',
            'message'       => $message,
            'priority'      => 'emergency',
            'action_url'    => '/views/provider/queue.php#urgent-followup-queue',
            'related_table' => 'urgent_followup_cases',
            'related_id'    => $caseId,
            'icon'          => 'alert-octagon',
        ]);

        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $providerId,
            'type'          => NotificationManager::TYPE_EMERGENCY,
            'title'         => 'Seek Emergency Care Immediately',
            'message'       => 'Your updated symptoms indicate a possible emergency. Do not wait for an appointment — go to the nearest Emergency Department or call your local emergency services now.',
            'priority'      => 'emergency',
            'action_url'    => '/views/patient/consultations.php',
            'related_table' => 'urgent_followup_cases',
            'related_id'    => $caseId,
        ]);
    }

    public static function urgentFollowupAccepted(
        PDO $pdo,
        int $patientId,
        string $patientName,
        int $caseId,
        int $consultationId,
        bool $videoStarted,
        ?int $providerId = null
    ): void {
        $title = $videoStarted ? 'Urgent Follow-up — Join Now' : 'Urgent Follow-up Accepted';
        $message = $videoStarted
            ? 'Your doctor has started an urgent follow-up video consultation. Please join now.'
            : 'Your doctor has accepted your urgent follow-up request. They will connect with you shortly.';

        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $providerId,
            'type'          => NotificationManager::TYPE_CONSULTATION,
            'title'         => $title,
            'message'       => $message,
            'priority'      => 'high',
            'action_url'    => '/views/patient/consultations.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
    }

    public static function caseReportSubmittedForAdmin(
        PDO $pdo,
        int $reportId,
        int $triageId,
        int $patientId,
        int $providerId,
        string $reason
    ): void {
        require_once __DIR__ . '/case_reports_schema.php';
        $label = case_report_reason_label($reason);
        NotificationManager::notifyAdmins($pdo, [
            'sender_id'     => $providerId,
            'type'          => NotificationManager::TYPE_WARNING,
            'title'         => 'Case Report Submitted',
            'message'       => "A provider reported case #{$triageId} ({$label}). Administrative review is required.",
            'priority'      => 'high',
            'action_url'    => '/views/admin/case_reports.php?id=' . $reportId,
            'related_table' => 'case_reports',
            'related_id'    => $reportId,
            'icon'          => 'flag',
        ]);
    }

    public static function caseReportEscalatedForSuperadmin(
        PDO $pdo,
        int $reportId,
        int $triageId,
        int $patientId,
        int $adminId
    ): void {
        NotificationManager::notifySuperadmins($pdo, [
            'sender_id'     => $adminId,
            'type'          => NotificationManager::TYPE_WARNING,
            'title'         => 'Case Report Escalated',
            'message'       => "Case report #{$reportId} for triage case #{$triageId} was escalated for Super Administrator review.",
            'priority'      => 'high',
            'action_url'    => '/views/admin/case_reports.php?id=' . $reportId,
            'related_table' => 'case_reports',
            'related_id'    => $reportId,
            'icon'          => 'alert-triangle',
        ]);
    }

    public static function caseTerminatedForPatient(
        PDO $pdo,
        int $patientId,
        int $triageId,
        ?int $providerId = null
    ): void {
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $providerId,
            'type'          => NotificationManager::TYPE_INFORMATION,
            'title'         => 'Consultation Case Closed',
            'message'       => 'Your current consultation case has been closed by the healthcare provider.',
            'action_url'    => '/views/patient/consultations.php',
            'related_table' => 'triage_results',
            'related_id'    => $triageId,
        ]);
    }

    public static function patientAccountRestricted(PDO $pdo, int $patientId, int $adminId): void
    {
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'  => $adminId,
            'type'       => NotificationManager::TYPE_WARNING,
            'title'      => 'Account Restriction',
            'message'    => 'Your account currently has a restriction that prevents new consultation submissions.',
            'action_url' => '/views/patient/dashboard.php',
            'related_table' => 'users',
            'related_id'    => $patientId,
        ]);
    }

    public static function patientAccountSuspended(PDO $pdo, int $patientId, int $adminId): void
    {
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'  => $adminId,
            'type'       => NotificationManager::TYPE_WARNING,
            'title'      => 'Account Suspended',
            'message'    => 'Your account is currently suspended. Please contact the health office for assistance.',
            'action_url' => '/views/patient/dashboard.php',
            'related_table' => 'users',
            'related_id'    => $patientId,
        ]);
    }

    public static function patientAccountRestored(PDO $pdo, int $patientId, int $adminId): void
    {
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'  => $adminId,
            'type'       => NotificationManager::TYPE_SUCCESS,
            'title'      => 'Account Access Restored',
            'message'    => 'Your account restrictions have been lifted. You may submit new health concerns when ready.',
            'action_url' => '/views/patient/triage.php',
            'related_table' => 'users',
            'related_id'    => $patientId,
        ]);
    }

    public static function consultationViolationReportedForAdmin(
        PDO $pdo,
        int $reportId,
        int $consultationId,
        int $patientId,
        int $providerId,
        string $reason
    ): void {
        require_once __DIR__ . '/case_reports_schema.php';
        $label = case_report_reason_label($reason);
        NotificationManager::notifyAdmins($pdo, [
            'sender_id'     => $providerId,
            'type'          => NotificationManager::TYPE_WARNING,
            'title'         => 'Possible Video Consultation Violation',
            'message'       => "A provider reported a possible violation during consultation #{$consultationId} ({$label}).",
            'priority'      => 'high',
            'action_url'    => '/views/admin/case_reports.php?id=' . $reportId,
            'related_table' => 'case_reports',
            'related_id'    => $reportId,
            'icon'          => 'flag',
        ]);
    }

    public static function consultationEndedForPatient(
        PDO $pdo,
        int $patientId,
        int $consultationId,
        ?int $providerId = null
    ): void {
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $providerId,
            'type'          => NotificationManager::TYPE_INFORMATION,
            'title'         => 'Video Consultation Ended',
            'message'       => 'Your current video consultation has been ended by the healthcare provider.',
            'action_url'    => '/views/patient/consultations.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
    }
}
