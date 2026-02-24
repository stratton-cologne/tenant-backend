<?php

namespace App\Services\Auth;

class LoginFlowService
{
    public function nextStep(string $mfaType): string
    {
        return $mfaType === 'app' ? 'verify_totp' : 'verify_mail_otp';
    }
}

// Tests fuer MFA Ablaufzeiten

// Audit Logging bei Lizenzupdates

// Backend Test Suite Basis erweitert

// Tenant Profil Passwortwechsel Validierung

// Frontend Fehlerbehandlung fuer API Calls

// Docs Betriebsrunbook fuer Migrationen

// Ablaufregeln fuer Lizenzgueltigkeit geschliffen

// RBAC Permission Namensschema module.action

// Module Editor Formularvalidierung erweitert

// QR Aktivierungsflow API Vertrag dokumentiert
