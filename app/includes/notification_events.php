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
            'action_url'    => '/views/superadmin/bhw_approvals.php',
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
            'action_url'    => '/views/admin/staff_management.php?role=bhw',
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
            'action_url'    => '/views/superadmin/doctor_approvals.php',
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
            'action_url'    => '/views/admin/staff_management.php?role=provider',
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
            'action_url'    => '/views/admin/queue_monitoring.php',
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
            'action_url'    => '/views/admin/queue_monitoring.php',
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

    public static function appointmentRescheduled(PDO $pdo, int $consultationId, int $patientId, int $providerId, string $newDate, ?int $senderId = null): void
    {
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'Appointment Rescheduled',
            'message'       => "Your appointment has been rescheduled to {$newDate}.",
            'action_url'    => '/views/patient/consultations.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
            'email'         => true,
        ]);
        NotificationManager::notifyProvider($pdo, $providerId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'Appointment Rescheduled',
            'message'       => "An appointment has been rescheduled to {$newDate}.",
            'action_url'    => '/views/provider/queue.php',
            'related_table' => 'consultations',
            'related_id'    => $consultationId,
        ]);
        NotificationManager::notifyBhwForPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_APPOINTMENT,
            'title'         => 'Consultation Rescheduled',
            'message'       => "Patient consultation rescheduled to {$newDate}.",
            'action_url'    => '/views/bhw/consultations/index.php',
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
            'action_url'    => '/views/admin/queue_monitoring.php',
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

    public static function consultationCompleted(PDO $pdo, int $consultationId, int $patientId, int $providerId, ?int $senderId = null): void
    {
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_SUCCESS,
            'title'         => 'Consultation Completed',
            'message'       => 'Your consultation has been completed. Review notes and prescriptions.',
            'action_url'    => '/views/patient/my_health.php?tab=files',
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
            'action_url'    => '/views/admin/queue_monitoring.php',
            'related_table' => 'triage_results',
            'related_id'    => $patientId,
        ]);
        NotificationManager::notifyBhwForPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_EMERGENCY,
            'title'         => 'High-Risk Patient Detected',
            'message'       => "{$patientName}: {$reason}",
            'priority'      => 'emergency',
            'action_url'    => '/views/bhw/triage/submit.php',
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
            'action_url'    => '/views/admin/queue_monitoring.php',
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
            'action_url'    => '/views/admin/queue_monitoring.php',
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

    public static function prescriptionAvailable(PDO $pdo, int $patientId, int $providerId, ?int $senderId = null): void
    {
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'     => $senderId,
            'type'          => NotificationManager::TYPE_MEDICAL,
            'title'         => 'Prescription Available',
            'message'       => 'A new prescription is available for you.',
            'action_url'    => '/views/patient/my_health.php?tab=files',
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
            'type'          => NotificationManager::TYPE_SECURITY,
            'title'         => 'Security Alert',
            'message'       => 'A failed login attempt was detected on your account.',
            'priority'      => 'critical',
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

    public static function followUpScheduled(PDO $pdo, int $patientId, string $date, ?int $providerId = null, ?int $senderId = null): void
    {
        NotificationManager::notifyPatient($pdo, $patientId, [
            'sender_id'  => $senderId,
            'type'       => NotificationManager::TYPE_REMINDER,
            'title'      => 'Follow-Up Scheduled',
            'message'    => "Your follow-up is scheduled for {$date}.",
            'action_url' => '/views/patient/dashboard.php#action-items',
            'email'      => true,
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
            'action_url'    => '/views/admin/queue_monitoring.php',
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
}
