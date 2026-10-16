<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

// Proteksi akses: Khusus Admin dan Staff Console
$is_admin = !empty($_SESSION['admin']) || !empty($_SESSION['staff_id']);

if (!$is_admin) {
    $redir = urlencode($_SERVER['REQUEST_URI'] ?? '/console/tepas');
    redirect("/console/login?next={$redir}");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Generator Bukti Penarikan - Demo Studio</title>
    
    <!-- External Frameworks & Fonts -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800;900&family=Roboto+Slab:wght@600;700;800&display=swap" rel="stylesheet" />

    <style>
        :root {
            --borcor: #e5e7eb;
            --bg-obsidian: #07090E;
            --card-glass: rgba(15, 23, 42, 0.88);
            --card-border: rgba(255, 255, 255, 0.09);
            --cyan-accent: #38BDF8;
            --emerald-accent: #10B981;
        }

        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            background-color: var(--bg-obsidian);
            color: #F1F5F9;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background-image: 
                radial-gradient(circle at 10% 10%, rgba(56, 189, 248, 0.12) 0%, transparent 40%),
                radial-gradient(circle at 90% 90%, rgba(16, 185, 129, 0.12) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(99, 102, 241, 0.08) 0%, transparent 60%),
                radial-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 1px);
            background-size: 100% 100%, 100% 100%, 100% 100%, 24px 24px;
        }

        /* ══ TOP NAVIGATION APP BAR ══ */
        .studio-top-bar {
            position: sticky;
            top: 0;
            z-index: 60;
            background: rgba(7, 9, 14, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .btn-studio-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 12px;
            color: #E2E8F0;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .btn-studio-back:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #FFFFFF;
            transform: translateY(-1px);
        }

        .studio-brand-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: rgba(56, 189, 248, 0.15);
            border: 1px solid rgba(56, 189, 248, 0.35);
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 800;
            color: #38BDF8;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .live-pulse-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #10B981;
            box-shadow: 0 0 8px #10B981;
            animation: pulseDot 1.8s infinite;
        }
        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
        }

        /* ══ MOBILE VIEW SWITCHER (ANDROID SEGMENTED TABS) ══ */
        .mobile-segment-tabs {
            display: none;
            margin: 12px 16px 0;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 14px;
            padding: 4px;
            position: sticky;
            top: 65px;
            z-index: 55;
            backdrop-filter: blur(16px);
        }
        .tab-btn-seg {
            flex: 1;
            padding: 9px 12px;
            border-radius: 10px;
            border: none;
            background: transparent;
            color: #94A3B8;
            font-size: 12.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .tab-btn-seg.active {
            background: linear-gradient(135deg, #0284C7 0%, #0EA5E9 100%);
            color: #FFFFFF;
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.35);
        }

        /* ══ MAIN WORKSPACE LAYOUT ══ */
        .studio-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px 16px 40px;
            display: flex;
            gap: 24px;
            align-items: flex-start;
        }

        /* ══ LEFT: EDITOR PANEL ══ */
        .studio-editor-panel {
            flex: 1;
            max-width: 440px;
            background: var(--card-glass);
            border: 1px solid var(--card-border);
            border-radius: 24px;
            padding: 24px;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.6);
            position: sticky;
            top: 75px;
        }

        .panel-header-badge {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .panel-icon-circle {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.2) 0%, rgba(14, 165, 233, 0.35) 100%);
            border: 1px solid rgba(56, 189, 248, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #38BDF8;
            font-size: 18px;
        }
        .panel-title {
            font-size: 16px;
            font-weight: 800;
            color: #F8FAFC;
            margin: 0 0 2px;
        }
        .panel-subtitle {
            font-size: 11.5px;
            color: #94A3B8;
            margin: 0;
        }

        /* Template Selector Visual Cards */
        .tpl-grid-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 18px;
        }
        .tpl-chip-card {
            background: rgba(30, 41, 59, 0.6);
            border: 1.5px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            gap: 4px;
            text-align: left;
        }
        .tpl-chip-card:hover {
            border-color: rgba(56, 189, 248, 0.4);
            background: rgba(30, 41, 59, 0.9);
            transform: translateY(-1px);
        }
        .tpl-chip-card.active {
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.2) 0%, rgba(3, 105, 161, 0.3) 100%);
            border-color: #38BDF8;
            box-shadow: 0 0 16px rgba(56, 189, 248, 0.25);
        }
        .tpl-chip-card.active.gopay-dark {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.15) 0%, rgba(20, 83, 45, 0.3) 100%);
            border-color: #22C55E;
            box-shadow: 0 0 16px rgba(34, 197, 94, 0.25);
        }
        .tpl-chip-card.active.bca-card {
            background: linear-gradient(135deg, rgba(0, 102, 204, 0.25) 0%, rgba(0, 60, 150, 0.4) 100%);
            border-color: #38BDF8;
            box-shadow: 0 0 16px rgba(56, 189, 248, 0.35);
        }
        .tpl-chip-card.active.bri-card {
            background: linear-gradient(135deg, rgba(0, 114, 206, 0.25) 0%, rgba(249, 115, 22, 0.25) 100%);
            border-color: #0284C7;
            box-shadow: 0 0 16px rgba(2, 132, 199, 0.35);
        }
        .chip-badge {
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
            padding: 2px 6px;
            border-radius: 6px;
            width: fit-content;
            line-height: 1;
        }
        .chip-badge.dana { background: rgba(26, 143, 232, 0.25); color: #38BDF8; }
        .chip-badge.gopay { background: rgba(0, 174, 214, 0.25); color: #22D3EE; }
        .chip-badge.bca { background: rgba(0, 102, 204, 0.35); color: #60A5FA; }
        .chip-badge.bri { background: rgba(0, 114, 206, 0.35); color: #38BDF8; }
        .chip-title { font-size: 11px; font-weight: 700; color: #E2E8F0; }

        /* Fullscreen styles for all templates */
        #wrapper_bca:fullscreen, #wrapper_bri:fullscreen,
        #wrapper_bca:-webkit-full-screen, #wrapper_bri:-webkit-full-screen,
        #wrapper_bca.is-fullscreen-active, #wrapper_bri.is-fullscreen-active {
            width: 100vw !important;
            height: 100vh !important;
            height: 100dvh !important;
            max-width: 480px !important;
            min-height: 100vh !important;
            max-height: 100vh !important;
            aspect-ratio: auto !important;
            overflow: hidden !important;
            margin: 0 auto !important;
            background-size: cover !important;
            background-position: center top !important;
            background-repeat: no-repeat !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
        }

        #wrapper_dana:fullscreen, #wrapper_gopay_light:fullscreen, #wrapper_gopay_dark:fullscreen,
        #wrapper_dana:-webkit-full-screen, #wrapper_gopay_light:-webkit-full-screen, #wrapper_gopay_dark:-webkit-full-screen,
        #wrapper_dana.is-fullscreen-active, #wrapper_gopay_light.is-fullscreen-active, #wrapper_gopay_dark.is-fullscreen-active {
            width: 100vw !important;
            min-height: 100vh !important;
            max-width: 480px !important;
            margin: 0 auto !important;
            overflow-y: auto !important;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }

        #wrapper_dana::-webkit-scrollbar, #wrapper_gopay_light::-webkit-scrollbar, #wrapper_gopay_dark::-webkit-scrollbar,
        #mobileFsOverlay::-webkit-scrollbar {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
        }

        #mobileFsOverlay {
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }

        /* Form Inputs */
        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 800;
            color: #94A3B8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .form-input-lux {
            width: 100%;
            background: rgba(8, 12, 22, 0.85);
            border: 1.5px solid rgba(255, 255, 255, 0.12);
            border-radius: 12px;
            padding: 10px 14px;
            color: #F8FAFC;
            font-size: 13.5px;
            font-weight: 600;
            transition: all 0.2s ease;
            outline: none;
        }
        .form-input-lux:focus {
            border-color: #38BDF8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2);
            background: rgba(8, 12, 22, 0.95);
        }
        .form-input-lux:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Quick Amount Pills */
        .quick-amounts-label {
            font-size: 10.5px;
            font-weight: 700;
            color: #64748B;
            margin: 8px 0 6px;
        }
        .quick-amounts-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 14px;
        }
        .btn-amt-pill {
            padding: 5px 10px;
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: #CBD5E1;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-amt-pill:hover, .btn-amt-pill:active {
            background: rgba(56, 189, 248, 0.2);
            border-color: #38BDF8;
            color: #38BDF8;
            transform: scale(1.03);
        }

        /* Action Buttons */
        .btn-action-fullscreen {
            width: 100%;
            background: linear-gradient(135deg, #0284C7 0%, #0369A1 100%);
            border: 1px solid rgba(56, 189, 248, 0.5);
            color: white;
            border-radius: 14px;
            padding: 13px 18px;
            font-size: 13.5px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            box-shadow: 0 8px 20px -5px rgba(2, 132, 199, 0.5);
            transition: all 0.25s ease;
            margin-top: 14px;
        }
        .btn-action-fullscreen:hover {
            background: linear-gradient(135deg, #0EA5E9 0%, #0284C7 100%);
            box-shadow: 0 10px 25px -4px rgba(14, 165, 233, 0.6);
            transform: translateY(-1px);
        }

        .btn-refresh-time {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94A3B8;
            border-radius: 12px;
            padding: 9px 14px;
            font-size: 11.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 8px;
        }
        .btn-refresh-time:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #E2E8F0;
        }

        /* ══ RIGHT: PREVIEW PANEL (PHONE MOCKUP STAGE) ══ */
        .studio-preview-panel {
            flex: 1.2;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
        }

        .preview-stage-card {
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 28px;
            padding: 20px 24px 30px;
            backdrop-filter: blur(16px);
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        .stage-badge-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            max-width: 440px;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        /* Phone Mockup Frame */
        .phone-mockup-frame {
            width: 100%;
            max-width: 420px;
            margin: 0 auto;
            border-radius: 34px;
            padding: 8px;
            background: #11141A;
            border: 2px solid rgba(255, 255, 255, 0.15);
            box-shadow: 
                0 0 0 1px #000000,
                0 25px 60px -15px rgba(0, 0, 0, 0.85),
                0 0 35px rgba(56, 189, 248, 0.12);
            position: relative;
            overflow: hidden;
            box-sizing: border-box;
        }

        /* Dynamic Island / Speaker Pill */
        .phone-mockup-island {
            width: 100px;
            height: 18px;
            background: #000000;
            border-radius: 12px;
            margin: 4px auto 8px;
            display: flex;
            align-items: center;
            justify-content: space-around;
            padding: 0 14px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .island-camera {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #1E293B;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .island-speaker {
            width: 32px;
            height: 3.5px;
            border-radius: 2px;
            background: #1E293B;
        }

        .hidden { display: none !important; }
        select#templateType { display: none !important; }

        .phone-inner-screen {
            border-radius: 24px;
            overflow-y: auto;
            overflow-x: hidden;
            max-height: 720px;
            background: #FFFFFF;
            position: relative;
            transform: translateZ(0);
            box-shadow: inset 0 0 2px rgba(0, 0, 0, 0.2);
            width: 100%;
            box-sizing: border-box;
        }
        .phone-inner-screen::-webkit-scrollbar {
            width: 4px;
        }
        .phone-inner-screen::-webkit-scrollbar-track {
            background: transparent;
        }
        .phone-inner-screen::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.15);
            border-radius: 4px;
        }

        /* Custom Wallpaper Inpaint / Text Cleanup Patches */
        .custom-wp-patch-center {
            position: absolute;
            left: 5%;
            right: 5%;
            top: 41%;
            height: 58px;
            z-index: 12;
            pointer-events: none;
            border-radius: 12px;
            transition: all 0.2s ease;
            backdrop-filter: blur(1px);
        }
        .custom-wp-patch-bottom {
            position: absolute;
            left: 15%;
            right: 15%;
            bottom: 84px;
            height: 44px;
            z-index: 12;
            pointer-events: none;
            border-radius: 22px;
            transition: all 0.2s ease;
            backdrop-filter: blur(1px);
        }

        /* ══ DEVICE & WALLPAPER SELECTORS ══ */
        .device-selector-box, .wallpaper-selector-box {
            margin-bottom: 14px;
            padding: 13px 15px;
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.09);
            border-radius: 16px;
        }

        .device-selector-header, .wallpaper-selector-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .device-title-text, .wallpaper-title-text {
            font-size: 11px;
            font-weight: 800;
            color: #94A3B8;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .auto-detect-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2.5px 8px;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.35);
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            color: #34D399;
        }

        .phone-model-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 7px;
        }
        @media (min-width: 640px) {
            .phone-model-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .btn-phone-model {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 9px 11px;
            background: rgba(30, 41, 59, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: #CBD5E1;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: left;
        }
        .btn-phone-model:hover {
            background: rgba(56, 189, 248, 0.12);
            border-color: rgba(56, 189, 248, 0.4);
            color: #FFFFFF;
        }
        .btn-phone-model.active {
            background: linear-gradient(135deg, rgba(2, 132, 199, 0.25) 0%, rgba(14, 165, 233, 0.35) 100%);
            border-color: #38BDF8;
            color: #FFFFFF;
            box-shadow: 0 0 12px rgba(56, 189, 248, 0.25);
        }

        .wallpaper-chips-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .btn-wp-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            background: rgba(30, 41, 59, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            color: #CBD5E1;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-wp-chip:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #FFFFFF;
        }
        .btn-wp-chip.active {
            border-color: #10B981;
            background: rgba(16, 185, 129, 0.2);
            color: #FFFFFF;
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.25);
        }

        .wp-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.4);
            display: inline-block;
            flex-shrink: 0;
        }

        /* ══ CUSTOM WALLPAPER UPLOAD ══ */
        .btn-custom-upload {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.12), rgba(30, 41, 59, 0.7));
            border: 1.5px dashed rgba(56, 189, 248, 0.45);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: left;
        }
        .btn-custom-upload:hover {
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.2), rgba(30, 41, 59, 0.85));
            border-color: rgba(56, 189, 248, 0.75);
            transform: translateY(-1px);
        }
        .custom-wp-active-card {
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid rgba(56, 189, 248, 0.35);
            border-radius: 12px;
            padding: 10px 12px;
        }
        .btn-change-wp, .btn-reset-wp {
            font-size: 10.5px;
            font-weight: 700;
            padding: 4px 9px;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
        }
        .btn-change-wp {
            background: rgba(56, 189, 248, 0.15);
            border: 1px solid rgba(56, 189, 248, 0.35);
            color: #38BDF8;
        }
        .btn-change-wp:hover {
            background: rgba(56, 189, 248, 0.25);
        }
        .btn-reset-wp {
            background: rgba(244, 63, 94, 0.12);
            border: 1px solid rgba(244, 63, 94, 0.3);
            color: #FB7185;
        }
        .btn-reset-wp:hover {
            background: rgba(244, 63, 94, 0.22);
        }

        /* ══ HARDWARE CUTOUTS & STATUS BAR ══ */
        .phone-dynamic-island {
            width: 110px;
            height: 28px;
            background: #000000;
            border-radius: 20px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 11px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.6);
            position: absolute;
            left: 50%;
            top: 10px;
            transform: translateX(-50%);
            z-index: 40;
        }
        .dynamic-island-cam {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #111827;
            border: 1.5px solid #1F2937;
        }
        .dynamic-island-sensor {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #0B0F19;
        }

        .phone-punch-hole {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #000000;
            border: 1.5px solid #1F2937;
            position: absolute;
            left: 50%;
            top: 11px;
            transform: translateX(-50%);
            z-index: 40;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.6);
        }

        .phone-classic-notch {
            width: 124px;
            height: 22px;
            background: #000000;
            border-bottom-left-radius: 14px;
            border-bottom-right-radius: 14px;
            position: absolute;
            left: 50%;
            top: 0;
            transform: translateX(-50%);
            z-index: 40;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.6);
        }
        .classic-notch-speaker {
            width: 38px;
            height: 3px;
            background: #1F2937;
            border-radius: 2px;
        }
        .classic-notch-cam {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #111827;
            border: 1px solid #1F2937;
        }

        .phone-mockup-notch {
            width: 120px;
            height: 18px;
            background: #000000;
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 12px;
            margin: 0 auto 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-top: none;
        }
        .notch-speaker {
            width: 32px;
            height: 3px;
            border-radius: 2px;
            background: #1E293B;
        }
        .notch-camera {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #1E293B;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .phone-waterdrop {
            width: 46px;
            height: 18px;
            background: #000000;
            border-bottom-left-radius: 14px;
            border-bottom-right-radius: 14px;
            position: absolute;
            left: 50%;
            top: 0;
            transform: translateX(-50%);
            z-index: 40;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .phone-waterdrop-cam {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #1F2937;
            margin-top: -2px;
        }

        /* ══ DYNAMIC STATUS BARS ══ */
        .dynamic-status-bar {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            width: 100%;
            padding: 9px 16px 4px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #FFFFFF;
            font-size: 13px;
            z-index: 35;
            pointer-events: auto;
            cursor: pointer;
            box-sizing: border-box;
            transition: opacity 0.15s ease;
        }
        .dynamic-status-bar:hover {
            opacity: 0.85;
        }

        .bezel-speaker-slit {
            width: 44px;
            height: 3.5px;
            border-radius: 2px;
            background: rgba(255, 255, 255, 0.25);
            margin: 4px auto 7px;
        }

        /* Xiaomi Signature Battery: Capsule with percentage inside */
        .xiaomi-battery-capsule {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 25px;
            height: 12px;
            border: 1.5px solid rgba(255, 255, 255, 0.9);
            border-radius: 3.5px;
            position: relative;
            margin-left: 2px;
            background: rgba(0, 0, 0, 0.25);
            box-sizing: border-box;
        }
        .xiaomi-battery-capsule::after {
            content: '';
            position: absolute;
            right: -3px;
            top: 2px;
            width: 1.5px;
            height: 4.5px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 0 1px 1px 0;
        }
        .xiaomi-battery-pct {
            font-size: 8px;
            font-weight: 800;
            line-height: 1;
            color: #FFFFFF;
            letter-spacing: -0.5px;
        }

        /* Samsung Signature Battery */
        .samsung-battery-box {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 10px;
            font-weight: 700;
        }
        .samsung-bat-icon {
            width: 18px;
            height: 10px;
            border: 1.5px solid rgba(255, 255, 255, 0.9);
            border-radius: 2px;
            position: relative;
            padding: 1px;
            box-sizing: border-box;
        }
        .samsung-bat-icon::after {
            content: '';
            position: absolute;
            right: -2.5px;
            top: 2px;
            width: 1.5px;
            height: 3.5px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 0 1px 1px 0;
        }
        .samsung-bat-fill {
            height: 100%;
            width: 85%;
            background: #FFFFFF;
            border-radius: 1px;
        }

        /* Vivo Signature HD badge & battery */
        .vivo-hd-badge {
            font-size: 8px;
            font-weight: 800;
            padding: 0.5px 3px;
            border: 1px solid rgba(255, 255, 255, 0.7);
            border-radius: 2px;
            line-height: 1;
            letter-spacing: -0.3px;
        }
        .vivo-battery-box {
            display: inline-flex;
            align-items: center;
            gap: 2.5px;
            font-size: 9.5px;
            font-weight: 700;
        }
        
        /* Infinix 4G+ & battery */
        .infinix-badge {
            font-size: 8.5px;
            font-weight: 800;
            padding: 0.5px 3.5px;
            background: rgba(16, 185, 129, 0.25);
            border: 1px solid rgba(16, 185, 129, 0.6);
            color: #34D399;
            border-radius: 2.5px;
            line-height: 1;
        }

        /* Pixel minimal battery */
        .pixel-battery-pill {
            width: 20px;
            height: 10px;
            border: 1.5px solid rgba(255, 255, 255, 0.85);
            border-radius: 3px;
            position: relative;
            display: inline-flex;
            align-items: center;
            padding: 1px;
            margin-left: 2px;
        }
        .pixel-battery-pill::after {
            content: '';
            position: absolute;
            right: -2.5px;
            top: 2px;
            width: 1.5px;
            height: 3.5px;
            background: rgba(255, 255, 255, 0.85);
            border-radius: 0 1px 1px 0;
        }
        .pixel-battery-fill {
            width: 100%;
            height: 100%;
            background: #FFFFFF;
            border-radius: 1px;
        }

        /* iOS Signature Battery */
        .ios-battery-pill {
            display: inline-flex;
            align-items: center;
            width: 22px;
            height: 11px;
            border: 1.5px solid rgba(255, 255, 255, 0.9);
            border-radius: 3.5px;
            position: relative;
            padding: 1px;
            box-sizing: border-box;
            margin-left: 2px;
        }
        .ios-battery-pill::after {
            content: '';
            position: absolute;
            right: -3px;
            top: 2px;
            width: 1.5px;
            height: 4px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 0 1px 1px 0;
        }
        .ios-battery-fill {
            height: 100%;
            width: 90%;
            background: #FFFFFF;
            border-radius: 1px;
        }

        .volte-badge {
            font-size: 7.5px;
            font-weight: 900;
            padding: 0.5px 3px;
            border: 1px solid rgba(255, 255, 255, 0.65);
            border-radius: 3px;
            letter-spacing: 0.2px;
            line-height: 1;
        }

        /* iOS Home Bar & Actions */
        .ios-bottom-container {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            width: 100%;
            padding: 0 32px 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
            z-index: 30;
            pointer-events: none;
        }
        .ios-actions-row {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        .ios-circle-btn {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFFFFF;
            font-size: 17px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        .ios-home-indicator {
            width: 134px;
            height: 4.5px;
            background: #FFFFFF;
            border-radius: 99px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
        }

        /* Android Bottom Navigation */
        .android-bottom-container {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            z-index: 30;
            pointer-events: none;
        }
        .android-trash-circle {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.25);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFFFFF;
            font-size: 16px;
            margin-bottom: 28px;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .android-nav-bar {
            width: 100%;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: space-around;
            padding: 0 40px;
            color: rgba(255, 255, 255, 0.85);
            font-size: 14px;
        }

        /* Form Addons & Master Buttons */
        .input-with-addon {
            display: flex;
            gap: 6px;
        }
        .input-with-addon .form-input-lux {
            flex: 1;
        }
        .btn-input-addon {
            padding: 0 12px;
            background: rgba(56, 189, 248, 0.15);
            border: 1px solid rgba(56, 189, 248, 0.4);
            border-radius: 12px;
            color: #38BDF8;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s ease;
        }
        .btn-input-addon:hover {
            background: rgba(56, 189, 248, 0.3);
            color: #FFFFFF;
        }

        .btn-master-random {
            width: 100%;
            background: linear-gradient(135deg, #7C3AED 0%, #6366F1 100%);
            border: 1px solid rgba(167, 139, 250, 0.5);
            color: white;
            border-radius: 14px;
            padding: 13px 18px;
            font-size: 13px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            box-shadow: 0 8px 20px -5px rgba(124, 58, 237, 0.5);
            transition: all 0.25s ease;
            margin-top: 10px;
        }
        .btn-master-random:hover {
            background: linear-gradient(135deg, #8B5CF6 0%, #4F46E5 100%);
            transform: translateY(-1px);
            box-shadow: 0 10px 25px -4px rgba(139, 92, 246, 0.6);
        }

        .btn-mandiri-chip {
            padding: 4px 8px;
            background: rgba(30, 58, 138, 0.5);
            border: 1px solid rgba(96, 165, 250, 0.4);
            border-radius: 8px;
            color: #93C5FD;
            font-size: 10.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-mandiri-chip:hover {
            background: rgba(37, 99, 235, 0.6);
            color: #FFFFFF;
            border-color: #60A5FA;
        }

        .btn-dana-chip {
            padding: 4px 8px;
            background: rgba(14, 116, 144, 0.35);
            border: 1px solid rgba(56, 189, 248, 0.35);
            border-radius: 8px;
            color: #7DD3FC;
            font-size: 10.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-dana-chip:hover {
            background: rgba(2, 132, 199, 0.6);
            color: #FFFFFF;
            border-color: #38BDF8;
        }

        /* ═════════════════════════════════════════════════════════════════
           ORIGINAL DANA & GOPAY RECEIPT STYLES (PRESERVED 100% UNTOUCHED)
           ═════════════════════════════════════════════════════════════════ */
        .dana-container { max-width: 500px; background-color: white; min-height: 80vh; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border-radius: 0.5rem; }
        .dana-header { background-color: #1A8FE8; border-top-left-radius: 0.5rem; border-top-right-radius: 0.5rem; padding: 1rem 1.25rem; padding-top: 1.7rem; color: white; display: flex; align-items: center; }
        .qris-card { background-color: white; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); padding: 1.5rem; border-top-left-radius: 0.5rem; border-top-right-radius: 0.5rem; position: relative; margin: 1rem 1rem 0; background-image: repeating-linear-gradient(45deg, transparent, transparent 100px, rgba(0, 168, 255, 0.03) 100px, rgba(0, 168, 255, 0.03) 200px); }
        .watermark-bg { position: absolute; inset: 0; opacity: 0.05; pointer-events: none; overflow:hidden; }
        .watermark-text { color: #1A8FE8; font-size: 3.75rem; font-weight: 700; transform: rotate(-40deg); position: absolute; }
        .watermark-text img { height: 3rem; }
        .qris-logo { height: 12rem; position: relative; z-index: 10; margin: 0 auto; display: block; }
        .transaction-info { display: flex; justify-content: space-between; font-size: 0.75rem; color: #9ca3af; position: relative; z-index: 10; }
        .dana-main { margin: 1rem; padding: 1.5rem; border: 1px solid var(--borcor); border-top: none; margin-top: 0; border-bottom-left-radius: 0.3rem; border-bottom-right-radius: 0.3rem; }
        .total-payment-box { background-color: #E3F2FD; border-radius: 0.5rem; padding: 1rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; }
        
        .dotted-divider { border-top: 1px dashed #E0E0E0; margin: 16px 0; }
        .dotted-divider-dark { border-top: 1px dashed #2A2C31; margin: 16px 0; }
        .font-main-amount { font-family: 'Rupa Serif', 'LFT Etica Sheriff', 'Roboto Slab', Georgia, serif; font-weight: 700; }
        .war-trakjil-text { color: #00B8D4; font-weight: 900; font-size: 11px; font-style: italic; text-transform: uppercase; line-height: 0.9; text-align: center; transform: skewX(-10deg); text-shadow: 1px 1px 0px #fff, -1px -1px 0px #fff, 1px -1px 0px #fff, -1px 1px 0px #fff; }

        @media (max-width: 1024px) {
            .studio-container { flex-direction: column; padding: 12px 10px 30px; }
            .studio-editor-panel { max-width: 100%; position: static; width: 100%; border-radius: 20px; padding: 18px; }
            .studio-preview-panel { width: 100%; max-width: 100%; }
            .mobile-segment-tabs { display: flex; }
            .mobile-hidden { display: none !important; }
        }

        @media (max-width: 480px) {
            .preview-stage-card {
                padding: 10px 6px !important;
                border-radius: 20px !important;
            }
            .stage-badge-header {
                max-width: 100% !important;
                padding-left: 2px !important;
                padding-right: 2px !important;
            }
            .phone-mockup-frame {
                max-width: 100% !important;
                border-radius: 24px !important;
                padding: 5px !important;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6) !important;
            }
            .phone-inner-screen {
                border-radius: 18px !important;
                max-height: calc(100vh - 210px) !important;
                min-height: 480px !important;
            }
            #wrapper_bca, #wrapper_bri, #wrapper_dana_notif {
                min-height: 600px !important;
            }
            #bca_preview_time, #bri_preview_time, #dana_notif_preview_time {
                font-size: 36px !important;
            }
        }
    </style>
</head>
<body>

    <!-- ══ STICKY TOP APP HEADER ══ -->
    <header class="studio-top-bar">
        <a href="/console/" class="btn-studio-back">
            <i class="fas fa-arrow-left"></i>
            <span>Kembali ke Console</span>
        </a>

        <div class="flex items-center gap-2">
            <span class="studio-brand-pill">
                <span class="live-pulse-dot"></span>
                <span>DEMO STUDIO</span>
            </span>
        </div>

        <button onclick="enterFullscreen()" class="btn-studio-back" title="Layar Penuh" style="padding: 8px 12px; color: #38BDF8;">
            <i class="fas fa-expand"></i>
        </button>
    </header>

    <!-- ══ MOBILE SEGMENTED CONTROL ══ -->
    <div class="mobile-segment-tabs" id="mobileSegmentTabs">
        <button type="button" class="tab-btn-seg active" id="btnTabEdit" onclick="switchMobileView('edit')">
            <i class="fas fa-sliders-h"></i>
            <span>Atur Data</span>
        </button>
        <button type="button" class="tab-btn-seg" id="btnTabPreview" onclick="switchMobileView('preview')">
            <i class="fas fa-mobile-alt"></i>
            <span>Lihat Bukti</span>
        </button>
    </div>

    <!-- ══ MAIN LAYOUT ══ -->
    <div class="studio-container">

        <!-- ═══════════════════════════════════════════════════════════════
             1. EDITOR PANEL (KONTROL DATA FORM)
             ═══════════════════════════════════════════════════════════════ -->
        <div class="studio-editor-panel" id="studioEditorPanel">
            
            <div class="panel-header-badge">
                <div class="panel-icon-circle">
                    <i class="fas fa-receipt"></i>
                </div>
                <div>
                    <h2 class="panel-title">Buat Bukti Penarikan</h2>
                    <p class="panel-subtitle">Generator simulasi bukti transfer resmi BCA, BRI, DANA & GoPay</p>
                </div>
            </div>

            <!-- Visual Template Selector -->
            <label class="form-label">Pilih Format Template</label>
            <div class="tpl-grid-selector">
                <div class="tpl-chip-card active" data-tpl="dana_receive" onclick="selectTemplate('dana_receive')">
                    <span class="chip-badge dana">DANA</span>
                    <span class="chip-title">Withdraw / Masuk</span>
                </div>
                <div class="tpl-chip-card gopay-dark" data-tpl="gopay_dark_topup" onclick="selectTemplate('gopay_dark_topup')">
                    <span class="chip-badge gopay">GOPAY DARK</span>
                    <span class="chip-title">Withdraw / Top Up</span>
                </div>
                <div class="tpl-chip-card bca-card" data-tpl="bca_notif" onclick="selectTemplate('bca_notif')">
                    <span class="chip-badge bca">BCA</span>
                    <span class="chip-title">myBCA Notifikasi</span>
                </div>
                <div class="tpl-chip-card bri-card" data-tpl="bri_notif" onclick="selectTemplate('bri_notif')">
                    <span class="chip-badge bri">BRI</span>
                    <span class="chip-title">BRImo Notifikasi</span>
                </div>
                <div class="tpl-chip-card dana-card" data-tpl="dana_notif" onclick="selectTemplate('dana_notif')">
                    <span class="chip-badge dana" style="background: linear-gradient(135deg, #118EEA, #005696);">DANA NOTIF</span>
                    <span class="chip-title">DANA Lockscreen</span>
                </div>
                <div class="tpl-chip-card" data-tpl="dana_payment" onclick="selectTemplate('dana_payment')">
                    <span class="chip-badge dana">DANA</span>
                    <span class="chip-title">Transfer / QRIS</span>
                </div>
                <div class="tpl-chip-card" data-tpl="gopay_light_pay" onclick="selectTemplate('gopay_light_pay')">
                    <span class="chip-badge gopay">GOPAY LIGHT</span>
                    <span class="chip-title">Transfer Keluar</span>
                </div>
            </div>

            <!-- Native Selector (Kept for Logic & Script Bindings) -->
            <select id="templateType" class="hidden" style="display: none !important;" onchange="syncTemplateChips(); updateFormDisplay(); updatePreview();">
                <option value="dana_receive">DANA - Withdraw</option>
                <option value="gopay_dark_topup">GOPAY (Dark) - Withdraw</option>
                <option value="bca_notif">BCA - myBCA Notifikasi</option>
                <option value="bri_notif">BRI - BRImo Notifikasi</option>
                <option value="dana_notif">DANA - Lockscreen Notifikasi</option>
                <option value="dana_payment">DANA - Transfer</option>
                <option value="gopay_light_pay">GOPAY (Light) - Transfer</option>
            </select>

            <!-- ── FORM DANA ── -->
            <div id="form_dana" class="form-group-wrapper">
                <div class="mb-3">
                    <label class="form-label" id="lblDanaMerchant">Nama Penerima / Pengirim</label>
                    <input type="text" id="danaMerchant" class="form-input-lux" value="MITRA DIGITAL" oninput="updatePreview()" placeholder="Cth: MITRA DIGITAL">
                </div>
                <div class="mb-3">
                    <div class="flex items-center justify-between mb-1">
                        <label class="form-label mb-0">Nomor Akun DANA</label>
                        <button type="button" class="text-[11px] text-sky-400 font-bold hover:underline flex items-center gap-1" onclick="randomizeDanaAccount()">
                            <i class="fas fa-random"></i> Acak Nomor DANA
                        </button>
                    </div>
                    <div class="input-with-addon">
                        <input type="text" id="danaReceiver" class="form-input-lux" value="0857•••5875" oninput="handleDanaReceiverInput(this)" onblur="formatDanaReceiverInput(this)" placeholder="Cth: 0857•••5875">
                        <button type="button" class="btn-input-addon" onclick="randomizeDanaAccount()" title="Acak Nomor Akun DANA">
                            <i class="fas fa-dice"></i> Acak
                        </button>
                    </div>
                    <span class="text-[10px] text-slate-400 mt-1 block">Tersensor otomatis dengan format bullet resmi DANA (Cth: 0857•••5875)</span>
                    <!-- Quick Operator Presets -->
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        <button type="button" class="btn-dana-chip" onclick="randomizeDanaAccount('indosat')">📶 Indosat (0857)</button>
                        <button type="button" class="btn-dana-chip" onclick="randomizeDanaAccount('telkomsel')">🔴 Telkomsel (0812)</button>
                        <button type="button" class="btn-dana-chip" onclick="randomizeDanaAccount('xl')">🔵 XL/Axis (0878)</button>
                        <button type="button" class="btn-dana-chip" onclick="randomizeDanaAccount('tri')">🟣 Tri (0896)</button>
                    </div>
                </div>
                <div class="mb-1">
                    <label class="form-label">Nominal Transaksi (Rp)</label>
                    <input type="number" id="danaAmount" class="form-input-lux" value="40000" oninput="updatePreview()" placeholder="40000">
                </div>
            </div>

            <!-- ── FORM GOPAY LIGHT ── -->
            <div id="form_gopay_light" class="form-group-wrapper hidden" style="display: none;">
                <div class="mb-3">
                    <label class="form-label" id="lblGopayLightName">Diterima dari / Pembayaran ke</label>
                    <input type="text" id="glName" class="form-input-lux" value="Firman Syahputra" oninput="updatePreview()" placeholder="Cth: Firman Syahputra">
                </div>
                <div class="mb-3">
                    <label class="form-label">Nomor GoPay Masking</label>
                    <input type="text" id="glNumber" class="form-input-lux" value="0852****2078" oninput="updatePreview()" placeholder="Cth: 0852****2078">
                </div>
                <div class="mb-1">
                    <label class="form-label">Jumlah Saldo (Rp)</label>
                    <input type="number" id="glAmount" class="form-input-lux" value="100000" oninput="updatePreview()" placeholder="100000">
                </div>
            </div>

            <!-- ── FORM GOPAY DARK ── -->
            <div id="form_gopay_dark" class="form-group-wrapper hidden" style="display: none;">
                <div class="mb-3">
                    <label class="form-label">Tipe Transaksi</label>
                    <input type="text" id="gdType" class="form-input-lux" value="GoPay Top Up" oninput="updatePreview()" disabled>
                </div>
                <div class="mb-3">
                    <label class="form-label">Metode Pengiriman (Via)</label>
                    <input type="text" id="gdVia" class="form-input-lux" value="Via Okeconnect" oninput="updatePreview()" placeholder="Cth: Via Okeconnect / Bank BCA">
                </div>
                <div class="mb-1">
                    <label class="form-label">Total Top Up (Rp)</label>
                    <input type="number" id="gdAmount" class="form-input-lux" value="42500" oninput="updatePreview()" placeholder="42500">
                </div>
            </div>

            <!-- ── FORM BCA NOTIFIKASI ── -->
            <div id="form_bca" class="form-group-wrapper hidden" style="display: none;">
                <div class="mb-3">
                    <label class="form-label">Nominal Masuk (Rp)</label>
                    <input type="number" id="bcaAmount" class="form-input-lux" value="24000" oninput="updatePreview()" placeholder="24000">
                </div>
                <div class="mb-3">
                    <div class="flex items-center justify-between mb-1">
                        <label class="form-label mb-0">Nama Pengirim</label>
                        <button type="button" class="text-[11px] text-sky-400 font-bold hover:underline flex items-center gap-1" onclick="randomizeBcaSender()">
                            <i class="fas fa-random"></i> Acak Pengirim
                        </button>
                    </div>
                    <div class="input-with-addon">
                        <input type="text" id="bcaSender" class="form-input-lux" value="MANDIRI BI-FAST" oninput="updatePreview()" placeholder="Cth: MANDIRI BI-FAST">
                        <button type="button" class="btn-input-addon" onclick="randomizeBcaSender()" title="Acak Pengirim Mandiri">
                            <i class="fas fa-dice"></i> Acak
                        </button>
                    </div>
                    <!-- Quick Mandiri Chips for BCA -->
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        <button type="button" class="btn-mandiri-chip" onclick="setMandiriBcaSender('MANDIRI BI-FAST')">⚡ MANDIRI BI-FAST</button>
                        <button type="button" class="btn-mandiri-chip" onclick="setMandiriBcaSender('BANK MANDIRI')">🏦 BANK MANDIRI</button>
                        <button type="button" class="btn-mandiri-chip" onclick="setMandiriBcaSender('TRF MANDIRI BI-FAST')">💳 TRF MANDIRI</button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Kategori Notifikasi</label>
                    <input type="text" id="bcaCategory" class="form-input-lux" value="Transfer Rekening" oninput="updatePreview()" placeholder="Transfer Rekening">
                </div>
                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="form-label mb-0">Jam Layar Kunci</label>
                            <button type="button" class="text-[10px] text-sky-400 font-bold hover:underline" onclick="refreshTimeNow()">Saat Ini</button>
                        </div>
                        <input type="text" id="bcaTime" class="form-input-lux" value="09.44" oninput="updatePreview()" placeholder="09.44">
                    </div>
                    <div>
                        <label class="form-label">Hari / Tanggal Layar</label>
                        <input type="text" id="bcaDate" class="form-input-lux" value="Sel, Sep 15" oninput="updatePreview()" placeholder="Sel, Sep 15">
                    </div>
                </div>
                <div class="mb-1">
                    <label class="form-label">Status Waktu Notifikasi</label>
                    <input type="text" id="bcaNotifTime" class="form-input-lux" value="sekarang" oninput="updatePreview()" placeholder="sekarang / 1m yang lalu">
                </div>
            </div>

            <!-- ── FORM BRI NOTIFIKASI ── -->
            <div id="form_bri" class="form-group-wrapper hidden" style="display: none;">
                <div class="mb-3">
                    <label class="form-label">Nominal Masuk (Rp)</label>
                    <input type="number" id="briAmount" class="form-input-lux" value="2586000" oninput="updatePreview()" placeholder="2586000">
                </div>
                <div class="mb-3">
                    <div class="flex items-center justify-between mb-1">
                        <label class="form-label mb-0">Nomor Rekening Penerima</label>
                        <button type="button" class="text-[11px] text-sky-400 font-bold hover:underline flex items-center gap-1" onclick="randomizeBriAccount()">
                            <i class="fas fa-random"></i> Acak Rekening
                        </button>
                    </div>
                    <div class="input-with-addon">
                        <input type="text" id="briAccount" class="form-input-lux" value="777401010338506" oninput="updatePreview()" placeholder="777401010338506">
                        <button type="button" class="btn-input-addon" onclick="randomizeBriAccount()" title="Acak Nomor Rekening BRI">
                            <i class="fas fa-dice"></i> Acak
                        </button>
                    </div>
                    <span class="text-[10px] text-slate-400 mt-1 block">Format 15 digit resmi BRI (Prefix 7774/0021/1056 dll)</span>
                </div>
                <div class="mb-3">
                    <div class="flex items-center justify-between mb-1">
                        <label class="form-label mb-0">Tanggal & Jam Transaksi</label>
                        <button type="button" class="text-[10px] text-sky-400 font-bold hover:underline" onclick="setNowTrxBri()">⏱️ Saat Ini</button>
                    </div>
                    <input type="text" id="briTrxDateTime" class="form-input-lux" value="15/09/2026 09:43:22" oninput="updatePreview()" placeholder="15/09/2026 09:43:22">
                </div>
                <div class="mb-3">
                    <div class="flex items-center justify-between mb-1">
                        <label class="form-label mb-0">Keterangan Transfer (KET.)</label>
                        <button type="button" class="text-[11px] text-sky-400 font-bold hover:underline flex items-center gap-1" onclick="setMandiriBriKet('random')">
                            <i class="fas fa-random"></i> Acak Mandiri
                        </button>
                    </div>
                    <div class="input-with-addon">
                        <input type="text" id="briKet" class="form-input-lux" value="MANDIRI BI-FAST REF:20260915849102 BUDI SANTOSO" oninput="updatePreview()" placeholder="MANDIRI BI-FAST...">
                        <button type="button" class="btn-input-addon" onclick="setMandiriBriKet('random')" title="Acak KET Mandiri">
                            <i class="fas fa-dice"></i> Acak
                        </button>
                    </div>
                    <!-- Quick Mandiri Chips for BRI -->
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        <button type="button" class="btn-mandiri-chip" onclick="setMandiriBriKet('bifast')">⚡ Mandiri BI-FAST</button>
                        <button type="button" class="btn-mandiri-chip" onclick="setMandiriBriKet('trf')">🏦 TRF Mandiri</button>
                        <button type="button" class="btn-mandiri-chip" onclick="setMandiriBriKet('livin')">📱 Mandiri Livin</button>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="form-label mb-0">Jam Layar Kunci</label>
                            <button type="button" class="text-[10px] text-sky-400 font-bold hover:underline" onclick="refreshTimeNow()">Saat Ini</button>
                        </div>
                        <input type="text" id="briTime" class="form-input-lux" value="09.43" oninput="updatePreview()" placeholder="09.43">
                    </div>
                    <div>
                        <label class="form-label">Hari / Tanggal Layar</label>
                        <input type="text" id="briDate" class="form-input-lux" value="Sel, 15 Sep" oninput="updatePreview()" placeholder="Sel, 15 Sep">
                    </div>
                </div>
                <div class="mb-1">
                    <label class="form-label">Status Waktu Notifikasi</label>
                    <input type="text" id="briNotifTime" class="form-input-lux" value="sekarang" oninput="updatePreview()" placeholder="sekarang / 1m yang lalu">
                </div>
            </div>

            <!-- ── FORM DANA NOTIFIKASI LOCKSCREEN ── -->
            <div id="form_dana_notif" class="form-group-wrapper hidden" style="display: none;">
                <div class="mb-3">
                    <label class="form-label">Nominal Top Up / Masuk (Rp)</label>
                    <input type="number" id="danaNotifAmount" class="form-input-lux" value="91269" oninput="updatePreview()" placeholder="91269">
                </div>
                <div class="mb-3">
                    <div class="flex items-center justify-between mb-1">
                        <label class="form-label mb-0">Nama Pengirim / Mitra</label>
                        <button type="button" class="text-[11px] text-sky-400 font-bold hover:underline flex items-center gap-1" onclick="randomizeDanaNotifSender()">
                            <i class="fas fa-random"></i> Acak Mitra
                        </button>
                    </div>
                    <div class="input-with-addon">
                        <input type="text" id="danaNotifSender" class="form-input-lux" value="MITRA DIGITAL" oninput="updatePreview()" placeholder="Cth: MITRA DIGITAL">
                        <button type="button" class="btn-input-addon" onclick="randomizeDanaNotifSender()" title="Acak Pengirim">
                            <i class="fas fa-dice"></i> Acak
                        </button>
                    </div>
                    <!-- Quick Sender Chips for DANA Notif -->
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        <button type="button" class="btn-dana-chip" onclick="setDanaNotifSender('MITRA DIGITAL')">Mitra Digital</button>
                        <button type="button" class="btn-dana-chip" onclick="setDanaNotifSender('BANK MANDIRI')">Bank Mandiri</button>
                        <button type="button" class="btn-dana-chip" onclick="setDanaNotifSender('BANK BCA')">Bank BCA</button>
                        <button type="button" class="btn-dana-chip" onclick="setDanaNotifSender('ALFAMART')">Alfamart</button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Tipe Transaksi Notifikasi</label>
                    <select id="danaNotifType" class="form-input-lux" onchange="updatePreview()">
                        <option value="topup" selected>Berhasil top up saldo (Masuk)</option>
                        <option value="transfer">Berhasil kirim uang (Keluar)</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="form-label mb-0">Jam Layar Kunci</label>
                            <button type="button" class="text-[10px] text-sky-400 font-bold hover:underline" onclick="refreshTimeNow()">Saat Ini</button>
                        </div>
                        <input type="text" id="danaNotifTime" class="form-input-lux" value="17.29" oninput="updatePreview()" placeholder="17.29">
                    </div>
                    <div>
                        <label class="form-label">Hari / Tanggal Layar</label>
                        <input type="text" id="danaNotifDate" class="form-input-lux" value="Min, Sep 6" oninput="updatePreview()" placeholder="Min, Sep 6">
                    </div>
                </div>
                <div class="mb-1">
                    <label class="form-label">Status Waktu Notifikasi</label>
                    <input type="text" id="danaNotifTimeAgo" class="form-input-lux" value="sekarang" oninput="updatePreview()" placeholder="sekarang / 1m yang lalu">
                </div>
            </div>

            <!-- ── KUSTOMISASI MODEL HP & WALLPAPER LOCKSCREEN (BCA, BRI & DANA) ── -->
            <div id="lockscreen_customizer" class="hidden" style="display: none;">
                
                <!-- Card Model HP -->
                <div class="device-selector-box" id="deviceSelectorBox">
                    <div class="device-selector-header">
                        <span class="device-title-text"><i class="fas fa-mobile-alt text-sky-400"></i> Model Smartphone</span>
                        <span class="auto-detect-badge" id="badgeAutoDetect">
                            <i class="fas fa-magic"></i> <span id="txtAutoDetect">Auto: Android</span>
                        </span>
                    </div>
                    <div class="phone-model-grid">
                        <button type="button" class="btn-phone-model" data-model="iphone" onclick="selectPhoneModel('iphone')">
                            <i class="fab fa-apple text-slate-200 text-sm"></i>
                            <div>
                                <div class="font-bold">iPhone 16 Pro</div>
                                <div class="text-[9.5px] opacity-70">Dynamic Island • iOS</div>
                            </div>
                        </button>
                        <button type="button" class="btn-phone-model" data-model="iphone_notch" onclick="selectPhoneModel('iphone_notch')">
                            <i class="fab fa-apple text-slate-300 text-sm"></i>
                            <div>
                                <div class="font-bold">iPhone 13 / 14</div>
                                <div class="text-[9.5px] opacity-70">Classic Notch • iOS</div>
                            </div>
                        </button>
                        <button type="button" class="btn-phone-model" data-model="samsung" onclick="selectPhoneModel('samsung')">
                            <i class="fas fa-mobile text-blue-400 text-sm"></i>
                            <div>
                                <div class="font-bold">Samsung Galaxy</div>
                                <div class="text-[9.5px] opacity-70">Punch-Hole • OneUI</div>
                            </div>
                        </button>
                        <button type="button" class="btn-phone-model" data-model="xiaomi" onclick="selectPhoneModel('xiaomi')">
                            <i class="fas fa-bolt text-amber-400 text-sm"></i>
                            <div>
                                <div class="font-bold">Xiaomi / POCO</div>
                                <div class="text-[9.5px] opacity-70">Center-Dot • HyperOS</div>
                            </div>
                        </button>
                        <button type="button" class="btn-phone-model active" data-model="oppo" onclick="selectPhoneModel('oppo')">
                            <i class="fas fa-circle-notch text-emerald-400 text-sm"></i>
                            <div>
                                <div class="font-bold">Oppo Reno</div>
                                <div class="text-[9.5px] opacity-70">Waterdrop • ColorOS</div>
                            </div>
                        </button>
                        <button type="button" class="btn-phone-model" data-model="vivo" onclick="selectPhoneModel('vivo')">
                            <i class="fas fa-gem text-indigo-400 text-sm"></i>
                            <div>
                                <div class="font-bold">Vivo V-Series</div>
                                <div class="text-[9.5px] opacity-70">Micro Punch • Funtouch</div>
                            </div>
                        </button>
                        <button type="button" class="btn-phone-model" data-model="realme" onclick="selectPhoneModel('realme')">
                            <i class="fas fa-rocket text-yellow-400 text-sm"></i>
                            <div>
                                <div class="font-bold">Realme GT</div>
                                <div class="text-[9.5px] opacity-70">Speed Punch • Realme UI</div>
                            </div>
                        </button>
                        <button type="button" class="btn-phone-model" data-model="infinix" onclick="selectPhoneModel('infinix')">
                            <i class="fas fa-fire text-orange-400 text-sm"></i>
                            <div>
                                <div class="font-bold">Infinix / Tecno</div>
                                <div class="text-[9.5px] opacity-70">Magic Ring • XOS</div>
                            </div>
                        </button>
                        <button type="button" class="btn-phone-model" data-model="pixel" onclick="selectPhoneModel('pixel')">
                            <i class="fab fa-google text-teal-400 text-sm"></i>
                            <div>
                                <div class="font-bold">Google Pixel 9</div>
                                <div class="text-[9.5px] opacity-70">Center-Dot • Pixel UI</div>
                            </div>
                        </button>
                    </div>
                </div>

                <!-- Card Wallpaper Lockscreen -->
                <div class="wallpaper-selector-box">
                    <div class="wallpaper-selector-header">
                        <span class="wallpaper-title-text"><i class="fas fa-image text-emerald-400"></i> Wallpaper Lockscreen</span>
                        <span class="text-[10px] text-slate-400 font-semibold" id="lblWpName">Bawaan Asli</span>
                    </div>

                    <div class="wallpaper-chips-row">
                        <button type="button" class="btn-wp-chip active" data-wp="default" onclick="selectWallpaper('default')">
                            <span class="wp-dot" style="background: linear-gradient(135deg, #1e293b, #475569);"></span>
                            <span>Bawaan Asli</span>
                        </button>
                        <button type="button" class="btn-wp-chip" data-wp="sunset" onclick="selectWallpaper('sunset')">
                            <span class="wp-dot" style="background: linear-gradient(135deg, #4338CA, #F59E0B);"></span>
                            <span>Sunset Aura</span>
                        </button>
                        <button type="button" class="btn-wp-chip" data-wp="ocean" onclick="selectWallpaper('ocean')">
                            <span class="wp-dot" style="background: linear-gradient(135deg, #0284C7, #06B6D4);"></span>
                            <span>Deep Ocean</span>
                        </button>
                        <button type="button" class="btn-wp-chip" data-wp="obsidian" onclick="selectWallpaper('obsidian')">
                            <span class="wp-dot" style="background: linear-gradient(135deg, #0A0A0C, #27272A);"></span>
                            <span>Obsidian Dark</span>
                        </button>
                        <button type="button" class="btn-wp-chip" data-wp="twilight" onclick="selectWallpaper('twilight')">
                            <span class="wp-dot" style="background: linear-gradient(135deg, #3B0764, #A21CAF);"></span>
                            <span>Twilight Purple</span>
                        </button>
                        <button type="button" class="btn-wp-chip" data-wp="emerald" onclick="selectWallpaper('emerald')">
                            <span class="wp-dot" style="background: linear-gradient(135deg, #064E3B, #10B981);"></span>
                            <span>Emerald Forest</span>
                        </button>
                        <button type="button" class="btn-wp-chip" data-wp="cyberpunk" onclick="selectWallpaper('cyberpunk')">
                            <span class="wp-dot" style="background: linear-gradient(135deg, #312E81, #EC4899);"></span>
                            <span>Cyber Neon</span>
                        </button>
                    </div>
                </div>

                <!-- Master Random & Refresh Button -->
                <button type="button" class="btn-master-random" onclick="randomizeAllAndSetNow()">
                    <i class="fas fa-dice text-lg"></i>
                    <span>🎲 Acak Rekening & Set Waktu Sekarang</span>
                </button>
            </div>

            <!-- Quick Nominal Chips -->
            <div class="quick-amounts-label">PILIHAN CEPAT NOMINAL:</div>
            <div class="quick-amounts-row">
                <button type="button" class="btn-amt-pill" onclick="setPresetAmount(25000)">25 Ribu</button>
                <button type="button" class="btn-amt-pill" onclick="setPresetAmount(50000)">50 Ribu</button>
                <button type="button" class="btn-amt-pill" onclick="setPresetAmount(100000)">100 Ribu</button>
                <button type="button" class="btn-amt-pill" onclick="setPresetAmount(250000)">250 Ribu</button>
                <button type="button" class="btn-amt-pill" onclick="setPresetAmount(500000)">500 Ribu</button>
                <button type="button" class="btn-amt-pill" onclick="setPresetAmount(1000000)">1 Juta</button>
                <button type="button" class="btn-amt-pill" onclick="setPresetAmount(2500000)">2.5 Juta</button>
                <button type="button" class="btn-amt-pill" onclick="setPresetAmount(5000000)">5 Juta</button>
            </div>

            <button type="button" class="btn-refresh-time" onclick="refreshTimeNow()">
                <i class="fas fa-sync-alt"></i>
                <span>Perbarui Jam & Tanggal ke Saat Ini</span>
            </button>

            <div class="mt-4 p-3 rounded-xl bg-blue-950/30 border border-blue-800/40 text-[11px] text-blue-300 flex items-start gap-2">
                <i class="fas fa-info-circle mt-0.5 text-blue-400"></i>
                <span>Klik <b>Mode Layar Penuh</b> untuk menangkap tangkapan layar (screenshot) manual bersih tanpa bilah peramban.</span>
            </div>

        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             2. PREVIEW PANEL (PHONE STAGE FRAME MOCKUP)
             ═══════════════════════════════════════════════════════════════ -->
        <div class="studio-preview-panel mobile-hidden" id="studioPreviewPanel">
            
            <div class="preview-stage-card">
                <div class="stage-badge-header">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-eye text-sky-400 text-xs"></i>
                        <span class="text-xs font-bold text-slate-200 tracking-wide">PRATINJAU BUKTI REALTIME</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="enterFullscreen()" class="text-[11.5px] font-bold text-sky-400 hover:text-sky-300 transition flex items-center gap-1.5 bg-sky-500/10 hover:bg-sky-500/20 px-3 py-1.5 rounded-xl border border-sky-500/30">
                            <i class="fas fa-expand"></i> <span>Layar Penuh (Screenshot)</span>
                        </button>
                    </div>
                </div>

                <!-- Phone Mockup Frame -->
                 <div class="phone-mockup-frame">
                    <div class="phone-mockup-island" id="mockupFrameIsland">
                        <div class="island-camera"></div>
                        <div class="island-speaker"></div>
                    </div>
                    <div class="phone-mockup-notch" id="mockupFrameNotch" style="display: none;">
                        <div class="notch-speaker"></div>
                        <div class="notch-camera"></div>
                    </div>
                    <div class="bezel-speaker-slit" id="mockupFrameBezel" style="display: none;"></div>

                    <div class="phone-inner-screen">

                        <!-- ══════════════════════════════════════════════════
                             TPL 1 & 2: DANA (EXACT ORIGINAL PRESERVED)
                             ══════════════════════════════════════════════════ -->
                        <div class="receipt-wrapper w-full flex justify-center hidden" id="tpl_dana">
                            <style>
                                #wrapper_dana {
                                    width: 100% !important;
                                    max-width: 412px !important;
                                    box-sizing: border-box !important;
                                    margin-left: auto !important;
                                    margin-right: auto !important;
                                    background-color: #F5F6F8 !important;
                                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif !important;
                                    position: relative !important;
                                    min-height: 100vh !important;
                                    overflow-x: hidden !important;
                                }
                                #wrapper_dana * {
                                    box-sizing: border-box !important;
                                }
                                .dana-watermark-bg {
                                    background-color: #ffffff !important;
                                    background-image: url('<?= base_url("assets/proof/dana_watermark_tile.png") ?>') !important;
                                    background-repeat: repeat !important;
                                    background-size: 170px 110px !important;
                                }
                                .dana-header {
                                    width: 100%;
                                    background-color: #118EEA;
                                    padding: 14px 16px 56px 16px;
                                }
                                .dana-header-content {
                                    display: flex;
                                    align-items: center;
                                    gap: 12px;
                                    color: #ffffff;
                                }
                                .dana-header-title {
                                    font-size: 17px;
                                    font-weight: 600;
                                    color: #ffffff;
                                    letter-spacing: -0.3px;
                                }
                                .dana-ticket-container {
                                    padding: 0 14px;
                                    margin-top: -46px;
                                    position: relative;
                                    z-index: 10;
                                }
                                .dana-ticket-card {
                                    width: 100%;
                                    border-radius: 14px 14px 0 0;
                                    border-top: 1px solid #EAEAEA;
                                    border-left: 1px solid #EAEAEA;
                                    border-right: 1px solid #EAEAEA;
                                    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.05);
                                    padding-top: 24px;
                                    padding-bottom: 6px;
                                    position: relative;
                                    overflow: hidden;
                                }
                                .dana-meta-row {
                                    display: flex;
                                    justify-content: space-between;
                                    align-items: center;
                                    padding: 0 16px;
                                    font-size: 11.5px;
                                    color: #8C96A3;
                                }
                                .dana-divider {
                                    width: 100%;
                                    padding: 0 16px;
                                    margin: 12px 0;
                                }
                                .dana-divider-line {
                                    border-bottom: 1px solid #F0F2F5;
                                }
                                .dana-status-row {
                                    display: flex;
                                    align-items: center;
                                    gap: 6px;
                                    padding: 0 16px;
                                    margin-bottom: 6px;
                                }
                                .dana-status-badge {
                                    width: 15px;
                                    height: 15px;
                                    background-color: #00BA88;
                                    border-radius: 50%;
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                    flex-shrink: 0;
                                }
                                .dana-status-text {
                                    color: #727C8E;
                                    font-size: 11.5px;
                                    font-weight: 500;
                                }
                                .dana-title-row {
                                    padding: 0 16px;
                                    margin-bottom: 12px;
                                }
                                .dana-title-text {
                                    font-size: 14px;
                                    font-weight: 700;
                                    color: #111827;
                                    line-height: 1.35;
                                    letter-spacing: -0.2px;
                                    margin: 0;
                                }
                                .dana-total-box {
                                    margin: 0 16px;
                                    background-color: #EDF6FD;
                                    border-radius: 8px;
                                    padding: 10px 14px;
                                    display: flex;
                                    align-items: center;
                                    justify-content: space-between;
                                }
                                .dana-total-label {
                                    color: #111827;
                                    font-weight: 700;
                                    font-size: 13.5px;
                                }
                                .dana-total-val {
                                    color: #111827;
                                    font-weight: 700;
                                    font-size: 14.5px;
                                }
                                .dana-detail-row {
                                    display: flex;
                                    align-items: center;
                                    justify-content: space-between;
                                    padding: 2px 16px;
                                    cursor: pointer;
                                }
                                .dana-detail-text {
                                    font-size: 13px;
                                    font-weight: 600;
                                    color: #111827;
                                }
                                .dana-banner-card {
                                    margin-top: 14px;
                                    background: #ffffff;
                                    border-radius: 12px;
                                    padding: 8px;
                                    border: 1px solid #E5E7EB;
                                    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
                                }
                                .dana-btn-share {
                                    width: 100%;
                                    margin-top: 12px;
                                    background-color: #ffffff;
                                    border: 1.5px solid #118EEA;
                                    color: #118EEA;
                                    font-weight: 700;
                                    font-size: 13.5px;
                                    letter-spacing: 0.5px;
                                    padding: 11px 0;
                                    border-radius: 8px;
                                    cursor: pointer;
                                    text-align: center;
                                    transition: background 0.15s ease;
                                }
                                .dana-btn-share:active {
                                    background-color: #EDF6FD;
                                }
                                .dana-legal-footer {
                                    margin-top: 16px;
                                    padding: 0 16px 20px 16px;
                                    text-align: center;
                                    color: #9CA3AF;
                                    font-size: 10px;
                                    line-height: 1.5;
                                }
                                .dana-sticky-bottom {
                                    position: relative !important;
                                    width: 100% !important;
                                    max-width: 100% !important;
                                    margin-left: auto !important;
                                    margin-right: auto !important;
                                    background: #ffffff !important;
                                    padding: 14px 16px 20px 16px !important;
                                    border-top: 1px solid #EAEAEA !important;
                                    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.03) !important;
                                    z-index: 10 !important;
                                }
                                .dana-btn-help {
                                    width: 100%;
                                    background: #ffffff;
                                    border: 1.5px solid #118EEA;
                                    color: #118EEA;
                                    font-weight: 700;
                                    font-size: 13.5px;
                                    padding: 10px 0;
                                    border-radius: 8px;
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                    gap: 8px;
                                    cursor: pointer;
                                    transition: background 0.15s ease;
                                }
                                .dana-btn-help:active {
                                    background-color: #EDF6FD;
                                }
                            </style>
                            <div id="wrapper_dana">
                                
                                <!-- Top Blue Header Extension -->
                                <div class="dana-header">
                                    <div class="dana-header-content">
                                        <div class="cursor-pointer flex items-center justify-center p-1 -ml-1 hover:opacity-80" onclick="exitFs()">
                                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="15 18 9 12 15 6"></polyline>
                                            </svg>
                                        </div>
                                        <div class="dana-header-title select-none">Detail Transaksi</div>
                                    </div>
                                </div>

                                <!-- Main Ticket Card Container (Floating over blue header) -->
                                <div class="dana-ticket-container">
                                    <!-- ONE Single Seamless Ticket Card -->
                                    <div class="dana-ticket-card dana-watermark-bg">
                                        
                                        <!-- Logo / Wallet Circle -->
                                        <div class="flex justify-center mb-5" id="logoWrapper" style="display:flex; justify-content:center; margin-bottom:20px;">
                                            <div class="bg-[#118EEA] rounded-full w-[76px] h-[76px] flex items-center justify-center shadow-[0_2px_8px_rgba(17,142,234,0.3)]" style="width:76px; height:76px; border-radius:50%; background:#118EEA; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 8px rgba(17,142,234,0.3);">
                                                <svg width="46" height="46" viewBox="0 0 48 48" fill="none">
                                                    <rect x="8" y="10" width="32" height="28" rx="5" fill="white"/>
                                                    <rect x="13" y="15" width="22" height="2.5" rx="1.25" fill="#118EEA"/>
                                                    <path d="M28 20.5C28 18.567 29.567 17 31.5 17H40V31H31.5C29.567 31 28 29.433 28 27.5V20.5Z" fill="white" stroke="#118EEA" stroke-width="2"/>
                                                    <circle cx="35" cy="24" r="2.2" fill="#118EEA"/>
                                                </svg>
                                            </div>
                                        </div>

                                        <!-- Date & DANA ID -->
                                        <div class="dana-meta-row">
                                            <span class="tpl-dana-dates whitespace-nowrap">15 Sep 2026 • 15:25</span>
                                            <span class="tpl-dana-id whitespace-nowrap" style="font-weight:500;">ID DANA 0898•••4707</span>
                                        </div>

                                        <!-- Thin Divider -->
                                        <div class="dana-divider">
                                            <div class="dana-divider-line"></div>
                                        </div>

                                        <!-- Status Badge -->
                                        <div class="dana-status-row">
                                            <div class="dana-status-badge">
                                                <svg width="9" height="9" viewBox="0 0 12 12" fill="none">
                                                    <path d="M2.5 6.2L4.8 8.5L9.5 3.5" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </div>
                                            <span class="status-label dana-status-text">Berhasil diterima</span>
                                        </div>

                                        <!-- Payment / Receive Title -->
                                        <div class="dana-title-row">
                                            <h2 class="payment-title dana-title-text">Terima Uang Rp318.784 dari MITRA DIGITAL</h2>
                                        </div>

                                        <!-- Total Box -->
                                        <div class="dana-total-box">
                                            <span class="total-label dana-total-label">Total Terima</span>
                                            <span class="total-amount dana-total-val">Rp318.784</span>
                                        </div>

                                        <!-- Thin Divider Below Total Box -->
                                        <div class="dana-divider" style="margin-top:12px; margin-bottom:10px;">
                                            <div class="dana-divider-line"></div>
                                        </div>

                                        <!-- Detail Transaksi Row -->
                                        <div class="dana-detail-row">
                                            <span class="dana-detail-text">Detail Transaksi</span>
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#9CA3AF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="6 9 12 15 18 9"></polyline>
                                            </svg>
                                        </div>
                                    </div>

                                    <!-- Scalloped Ticket Edge (Gerigi Tiket Menyatu) -->
                                    <div style="width:100%; height:10px; overflow:hidden; border-left:1px solid #EAEAEA; border-right:1px solid #EAEAEA; margin-top:-1px;">
                                        <svg width="100%" height="10" preserveAspectRatio="none" style="display:block;">
                                            <defs>
                                                <pattern id="dana_scallop_pat" x="0" y="0" width="16" height="10" patternUnits="userSpaceOnUse">
                                                    <rect width="16" height="10" fill="white"/>
                                                    <path d="M 3.5,10 A 4.5,4.5 0 0,1 12.5,10" fill="#F5F6F8" stroke="#EAEAEA" stroke-width="1.2"/>
                                                    <line x1="0" y1="9.4" x2="3.5" y2="9.4" stroke="#EAEAEA" stroke-width="1.2"/>
                                                    <line x1="12.5" y1="9.4" x2="16" y2="9.4" stroke="#EAEAEA" stroke-width="1.2"/>
                                                </pattern>
                                            </defs>
                                            <rect width="100%" height="10" fill="url(#dana_scallop_pat)"/>
                                        </svg>
                                    </div>

                                    <!-- Promo Banner Card -->
                                    <div class="dana-banner-card">
                                        <img src="<?= base_url('assets/proof/dana_protection.png') ?>" alt="Aman Pakai DANA" style="width:100%; border-radius:6px; display:block;">
                                    </div>

                                    <!-- BAGIKAN Button -->
                                    <button type="button" class="dana-btn-share">BAGIKAN</button>

                                    <!-- Secured By Legal Footer -->
                                    <div class="dana-legal-footer">
                                        <div style="display:flex; justify-content:center; align-items:center; gap:6px; margin-bottom:8px;">
                                            <span style="color:#64748B; font-size:11px;">Diamankan oleh</span>
                                            <svg width="14" height="18" viewBox="0 0 20 28" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-top:1px;">
                                                <path d="M5.2 11V6.5C5.2 3.6 7.4 1.5 10 1.5C12.6 1.5 14.8 3.6 14.8 6.5V11" stroke="#118EEA" stroke-width="2.8" stroke-linecap="round"/>
                                                <rect x="1.5" y="10" width="17" height="16.5" rx="7" fill="#118EEA"/>
                                                <path d="M1.5 18 C4.5 18, 6 16.5, 8.5 16.5 C11 16.5, 12.5 18, 15 18 C16.5 18, 17.5 17.5, 18.5 17 V20 C17.5 20.5, 16.5 21, 15 21 C12.5 21, 11 19.5, 8.5 19.5 C6 19.5, 4.5 21, 1.5 21 V18 Z" fill="white"/>
                                            </svg>
                                            <span style="color:#118EEA; font-size:11px; font-weight:700;">DANA PROTECTION</span>
                                        </div>
                                        <p>*Termasuk PPN</p>
                                        <p>PT Espay Debit Indonesia Koe</p>
                                        <p>NPWP: 073.210.332.0-613.000</p>
                                        <p style="padding:0 8px; margin-top:2px;">Capital Place Lt. 18, Jl. Jend. Gatot Subroto Kav. 18, Kuningan Barat, Mampang Prapatan</p>
                                        <p>Jakarta Selatan. DKI Jakarta - 12710</p>
                                    </div>
                                </div>

                                <!-- Sticky Bottom Button (BUTUH BANTUAN?) -->
                                <div class="dana-sticky-bottom">
                                    <button type="button" class="dana-btn-help">
                                        <div style="display:flex; align-items:center;">
                                            <img src="<?= base_url('assets/proof/help_icon.png') ?>" alt="Help icon" style="height:28px; object-fit:contain;">
                                        </div>
                                        <span>BUTUH BANTUAN?</span>
                                    </button>
                                </div>

                            </div>
                        </div>

                        <!-- ══════════════════════════════════════════════════
                             TPL 2: GOPAY LIGHT (EXACT ORIGINAL PRESERVED)
                             ══════════════════════════════════════════════════ -->
                        <div class="receipt-wrapper w-full flex justify-center hidden" id="tpl_gopay_light">
                            <div id="wrapper_gopay_light" class="w-full max-w-md mx-auto bg-[#F0F2F5] font-sans flex flex-col justify-between" style="min-height: 100vh; position: relative;">
                                <div class="w-full flex flex-col">
                                    <div class="px-5 py-4 flex items-center justify-between bg-[#F0F2F5]">
                                        <div class="text-[#4A4A4A] text-[20px] w-8 h-8 flex items-center justify-start cursor-pointer" onclick="exitFs()">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                        </div>
                                        <div class="flex items-center gap-4">
                                            <div class="text-[#4A4A4A] text-[20px] w-8 h-8 flex items-center justify-center">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line></svg>
                                            </div>
                                            <div class="bg-[#4A4A4A] text-white rounded-full w-[22px] h-[22px] flex items-center justify-center">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="px-4">
                                        <div class="w-full bg-white rounded-[24px] px-5 pt-8 pb-5 shadow border border-[#F5F5F5]">
                                            <div class="flex justify-center mb-5">
                                                <div class="bg-[#B3E5FC] rounded-full w-[64px] h-[64px] flex items-center justify-center">
                                                    <svg width="34" height="34" viewBox="0 0 24 24" fill="#00AED6" xmlns="http://www.w3.org/2000/svg">
                                                        <path d="M16 11C17.6569 11 19 9.65685 19 8C19 6.34315 17.6569 5 16 5C14.3431 5 13 6.34315 13 8C13 9.65685 14.3431 11 16 11Z"/>
                                                        <path d="M9 12C11.2091 12 13 10.2091 13 8C13 5.79086 11.2091 4 9 4C6.79086 4 5 5.79086 5 8C5 10.2091 6.79086 12 9 12Z"/>
                                                        <path d="M16 13C17.6569 13 21 13.8284 21 15.5V19H17V17.5C17 16.4293 16.586 15.4111 15.9336 14.5661C16.9115 13.91 17.9174 13.4308 19 13.4308C17.8967 13.3101 16.852 13.3101 16 13Z"/>
                                                        <path d="M9 14C6.23858 14 3 15.6569 3 18.5V20H15V18.5C15 15.6569 11.7614 14 9 14Z"/>
                                                    </svg>
                                                </div>
                                            </div>
                                            <div class="text-center mb-1"><div class="tpl-amount text-[#00AA13] text-[32px] font-main-amount tracking-tight">Rp100.000</div></div>
                                            <div class="tpl-merchant text-center text-[#4A4A4A] text-[15px] mb-1 font-medium">Diterima dari ladang</div>
                                            <div class="tpl-number text-center text-[#757575] text-[13px] mb-8">GoPay 0852****2078</div>
                                            <div class="dotted-divider mb-5"></div>
                                            <div class="text-[#1C1C1C] font-bold text-[15px] mb-4">Rincian transaksi</div>
                                            <div class="space-y-3.5">
                                                <div class="flex justify-between"><span class="text-[#757575] text-[14px]">Status</span><span class="text-[#00AA13] font-bold text-[14px] flex items-center gap-1.5">Selesai <div class="bg-[#00AA13] rounded-full w-4 h-4 flex items-center justify-center flex-shrink-0"><svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M2.5 6.2L4.8 8.5L9.5 3.5" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></div></span></div>
                                                <div class="flex justify-between"><span class="text-[#757575] text-[14px]">Ditambahkan ke</span><span class="text-[#4A4A4A] text-[14px] font-medium flex items-center gap-1.5">GoPay Saldo <svg class="w-4 h-4 shrink-0 inline-block" viewBox="0 0 170 170" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="85" cy="85" r="85" fill="#00AED6"/><g transform="translate(11.7, 1.1)"><path fill-rule="evenodd" clip-rule="evenodd" fill="#FFFFFF" d="M122.9,81.4c-0.7-10.6-9.9-19.1-20.6-18.4H48.5c-2.1,0-3.5-1.4-3.5-3.5c0-2.1,1.4-3.5,3.5-3.5H103c-0.4-7.1-5.7-14.5-12.4-15.6c-15.9-2.8-32.2-2.8-47.8,0c-8.9,1.4-15.6,10.3-17,18.8c-2.5,16.7-2.5,33.6,0,50.3c1.8,9.6,9.6,17.4,19.5,18.8c19.8,2.5,39.7,2.5,59.2,0c8.9-1.1,14.5-9.2,16.3-17.7C122.5,100.6,123.2,91,122.9,81.4z M105.2,93.1v3.2c0,2.1-1.4,3.5-3.5,3.5s-3.5-1.4-3.5-3.5v-3.2c-1.1-1.1-1.8-2.5-1.8-3.9c0-2.8,2.5-5.3,5.3-5.3c2.8,0,5.3,2.5,5.3,5.3C106.9,90.6,106.2,92.1,105.2,93.1z"/></g></svg></span></div>
                                                <div class="flex justify-between"><span class="text-[#757575] text-[14px]">Waktu</span><span class="tpl-time text-[#4A4A4A] text-[14px] font-medium">4:20</span></div>
                                                <div class="flex justify-between"><span class="text-[#757575] text-[14px]">Tanggal</span><span class="tpl-date text-[#4A4A4A] text-[14px] font-medium">13 Mar 2026</span></div>
                                                <div class="flex justify-between"><span class="text-[#757575] text-[14px]">ID transaksi</span><span class="text-[#4A4A4A] text-[14px] font-medium flex items-center gap-1.5"><span class="tpl-trx">0520260312212006c...</span> <div class="w-[13px] h-[13px] flex items-center justify-center opacity-80" style="line-height:0;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#757575" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg></div></span></div>
                                            </div>
                                            <div class="dotted-divider my-4"></div>
                                            <div class="flex justify-between"><span class="text-[#757575] text-[14px]">Jumlah</span><span class="tpl-amount text-[#4A4A4A] text-[14px]">Rp100.000</span></div>
                                            <div class="dotted-divider my-4"></div>
                                            <div class="flex justify-between mb-6"><span class="text-[#1C1C1C] font-bold text-[15px]">Total</span><span class="tpl-total text-[#1C1C1C] font-extrabold text-[16px]">Rp40.000</span></div>
                                            <div class="w-full bg-white border border-[#E0E0E0] rounded-[20px] py-2.5 flex items-center justify-center gap-2 text-[#4A4A4A] text-[13px] font-medium">Tutup <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#757575" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 15l-6-6-6 6"/></svg></div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Bottom Splash Light -->
                                <div class="w-full rounded-t-[20px] overflow-hidden bg-white shadow-[0_-4px_20px_rgba(0,0,0,0.08)] mt-6 z-20">
                                    <div class="bg-[#00B8D4] text-white text-center py-3 text-[13px] font-medium w-full relative">
                                        Transfer sesama GoPay, dapet puzzle, menang miliaran!
                                        <div class="absolute -bottom-1 left-0 right-0 h-2 bg-[#00B8D4]"></div>
                                    </div>
                                    <div class="bg-white pt-4 pb-6 px-3 flex justify-between items-start relative z-10 w-full mx-auto">
                                        <div class="flex flex-col items-center justify-start flex-1">
                                            <div class="bg-[#E1F5FE] rounded-[16px] w-[90%] max-w-[72px] h-[54px] flex items-center justify-center mb-2"><svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M22 2L11 13" stroke="#00AED6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M22 2L15 22L11 13L2 9L22 2Z" fill="#00AED6" stroke="#00AED6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                            <div class="text-[#757575] text-[11px] font-medium text-center leading-snug w-[90%]">Transfer gratis</div>
                                        </div>
                                        <div class="flex flex-col items-center justify-start flex-1">
                                            <div class="bg-[#E1F5FE] rounded-[16px] w-[90%] max-w-[72px] h-[54px] flex items-center justify-center mb-2"><div class="bg-[#00AED6] rounded-full w-[28px] h-[28px] flex items-center justify-center shadow-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line></svg></div></div>
                                            <div class="text-[#757575] text-[11px] font-medium text-center leading-snug w-[90%]">Bagi bukti bay...</div>
                                        </div>
                                        <div class="flex flex-col items-center justify-start flex-1">
                                            <div class="bg-[#E1F5FE] rounded-[16px] w-[90%] max-w-[72px] h-[54px] flex items-center justify-center mb-2 relative overflow-hidden"><div class="war-trakjil-text">WAR<br>TRAKJIL</div></div>
                                            <div class="text-[#757575] text-[11px] font-medium text-center leading-snug w-[90%]">War Trakjil</div>
                                        </div>
                                        <div class="flex flex-col items-center justify-start flex-1">
                                            <div class="bg-[#FFF3E0] rounded-[16px] w-[90%] max-w-[72px] h-[54px] flex items-center justify-center mb-2"><div class="relative w-[28px] h-[22px]"><div class="absolute top-0 right-0 w-[22px] h-[16px] bg-[#FFB74D] rounded-[3px] border-[1.5px] border-white transform rotate-12"></div><div class="absolute bottom-0 left-0 w-[22px] h-[16px] bg-[#FF9800] rounded-[3px] border-[1.5px] border-white z-10 flex items-center justify-center"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"></polyline><rect x="2" y="7" width="20" height="5"></rect><line x1="12" y1="22" x2="12" y2="7"></line><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"></path><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"></path></svg></div></div></div>
                                            <div class="text-[#757575] text-[11px] font-medium text-center leading-snug w-[90%]">Kartu gosok</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ══════════════════════════════════════════════════
                             TPL 3: GOPAY DARK (EXACT ORIGINAL PRESERVED)
                             ══════════════════════════════════════════════════ -->
                        <div class="receipt-wrapper w-full flex justify-center hidden" id="tpl_gopay_dark">
                            <div id="wrapper_gopay_dark" class="w-full max-w-md mx-auto bg-[#121418] text-white font-sans flex flex-col justify-between" style="min-height: 100vh; position: relative;">
                                <div class="w-full flex flex-col">
                                    <!-- Header -->
                                    <div class="px-5 py-4 flex items-center justify-between bg-[#121418]">
                                        <button class="text-[#E0E0E0] w-8 h-8 flex items-center justify-start cursor-pointer" onclick="exitFs()">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#E0E0E0" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:block;"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                        </button>
                                        <div class="flex items-center gap-4">
                                            <button class="text-[#E0E0E0] w-8 h-8 flex items-center justify-center">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#E0E0E0" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:block; margin:auto;"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line></svg>
                                            </button>
                                            <button class="bg-[#2A2C31] text-[#E0E0E0] rounded-full w-[24px] h-[24px] flex items-center justify-center shadow-sm p-0 border-0" style="line-height:0;">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#E0E0E0" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" style="display:block; margin:auto;"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Main Content Card -->
                                    <div class="px-4">
                                        <div class="w-full bg-[#22252A] rounded-[24px] px-5 pt-5 pb-5 mx-auto shadow-lg border border-[#2A2D33]">
                                            <!-- Icon -->
                                            <div class="flex justify-center mb-5">
                                                <div class="bg-[#004A5C] rounded-full w-[72px] h-[72px] flex items-center justify-center relative shadow-inner">
                                                    <div class="bg-[#00AED6] rounded-full w-[36px] h-[36px] flex items-center justify-center" style="line-height:0;">
                                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#004A5C" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" style="display:block; margin:auto;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Amount -->
                                            <div class="text-center mb-1">
                                                <div class="tpl-amount text-[#00AA13] text-[34px] font-main-amount tracking-tight">Rp42.500</div>
                                            </div>
                                            
                                            <!-- Recipient -->
                                            <div class="tpl-merchant text-center text-[#E0E0E0] text-[15px] mb-1 font-medium">
                                                GoPay Top Up
                                            </div>

                                            <!-- GoPay Number -->
                                            <div class="tpl-number text-center text-[#9E9E9E] text-[13px] mb-4 font-normal">
                                                Via Okeconnect
                                            </div>

                                            <!-- TRX Box -->
                                            <div class="bg-[#1C1F26] rounded-[10px] py-4 px-5 flex items-center border border-[#2B303A] shadow-[inset_0_1px_0_rgba(255,255,255,0.02),0_1px_2px_rgba(0,0,0,0.15)] mb-3 relative">
                                                <div class="absolute left-4 opacity-80">
                                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                        <path d="M7 8H10V11.5H8.5C8.5 13.5 9.5 14.5 10.5 15L9.5 16.5C7.5 15.5 5.5 13.5 5.5 11.5V8H7ZM15 8H18V11.5H16.5C16.5 13.5 17.5 14.5 18.5 15L17.5 16.5C15.5 15.5 13.5 13.5 13.5 11.5V8H15Z" fill="#9CA3AF"/>
                                                    </svg>
                                                </div>
                                                <div class="tpl-trx-box w-full text-center text-[#F3F4F6] text-[14.5px] font-medium tracking-wide">TRX1139038824</div>
                                            </div>

                                            <!-- Chat CS Box -->
                                            <div class="bg-[#1C1F26] rounded-full py-2.5 px-4 flex items-center justify-between border border-[#2B303A] shadow-[inset_0_1px_0_rgba(255,255,255,0.02),0_1px_2px_rgba(0,0,0,0.15)] mb-4 hover:bg-[#232730] transition cursor-pointer">
                                                <div class="flex items-center gap-3">
                                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                        <path d="M5 13V11C5 7.13401 8.13401 4 12 4C15.866 4 19 7.13401 19 11V13" stroke="#5C66F1" stroke-width="2.2" stroke-linecap="round"/>
                                                        <rect x="3.5" y="11" width="5" height="7.5" rx="2.5" fill="#22D3EE"/>
                                                        <rect x="15.5" y="11" width="5" height="7.5" rx="2.5" fill="#22D3EE"/>
                                                        <path d="M18 17.5V19.5C18 20.3284 17.3284 21 16.5 21H13" stroke="#5C66F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                        <circle cx="11.5" cy="21" r="1.5" fill="#22D3EE"/>
                                                    </svg>
                                                    <div class="text-[#F3F4F6] text-[14.5px] font-medium tracking-wide">Chat dengan CS</div>
                                                </div>
                                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M4 12H20M20 12L14 6M20 12L14 18" stroke="#10B981" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </div>

                                            <div class="dotted-divider-dark mb-5 border-t-[1px] border-dashed border-[#2A2C31]"></div>

                                            <!-- Transaction Details Title -->
                                            <div class="text-white font-bold text-[16px] mb-4">
                                                Rincian transaksi
                                            </div>

                                            <!-- Detail Items -->
                                            <div class="space-y-2.5">
                                                <div class="flex justify-between items-center">
                                                    <div class="text-[#A0A0A0] text-[14px]">Status</div>
                                                    <div class="text-[#00AA13] font-medium text-[14px] flex items-center gap-1.5">
                                                        Selesai 
                                                        <div class="bg-[#00AA13] rounded-full w-[16px] h-[16px] flex items-center justify-center" style="line-height:0;">
                                                            <svg width="10" height="10" viewBox="0 0 12 12" fill="none" style="display:block; margin:auto;"><path d="M2.5 6.2L4.8 8.5L9.5 3.5" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="flex justify-between items-center">
                                                    <div class="text-[#A0A0A0] text-[14px]">Ditambahkan ke</div>
                                                    <div class="text-[#E0E0E0] text-[14px] flex items-center gap-1.5 font-medium">
                                                        GoPay Saldo 
                                                        <svg class="w-4 h-4 shrink-0 inline-block" viewBox="0 0 170 170" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="85" cy="85" r="85" fill="#00AED6"/><g transform="translate(11.7, 1.1)"><path fill-rule="evenodd" clip-rule="evenodd" fill="#FFFFFF" d="M122.9,81.4c-0.7-10.6-9.9-19.1-20.6-18.4H48.5c-2.1,0-3.5-1.4-3.5-3.5c0-2.1,1.4-3.5,3.5-3.5H103c-0.4-7.1-5.7-14.5-12.4-15.6c-15.9-2.8-32.2-2.8-47.8,0c-8.9,1.4-15.6,10.3-17,18.8c-2.5,16.7-2.5,33.6,0,50.3c1.8,9.6,9.6,17.4,19.5,18.8c19.8,2.5,39.7,2.5,59.2,0c8.9-1.1,14.5-9.2,16.3-17.7C122.5,100.6,123.2,91,122.9,81.4z M105.2,93.1v3.2c0,2.1-1.4,3.5-3.5,3.5s-3.5-1.4-3.5-3.5v-3.2c-1.1-1.1-1.8-2.5-1.8-3.9c0-2.8,2.5-5.3,5.3-5.3c2.8,0,5.3,2.5,5.3,5.3C106.9,90.6,106.2,92.1,105.2,93.1z"/></g></svg>
                                                    </div>
                                                </div>
                                                <div class="flex justify-between items-center">
                                                    <div class="text-[#A0A0A0] text-[14px]">Waktu</div>
                                                    <div class="tpl-time text-[#E0E0E0] text-[14px] font-medium">13:16</div>
                                                </div>
                                                <div class="flex justify-between items-center">
                                                    <div class="text-[#A0A0A0] text-[14px]">Tanggal</div>
                                                    <div class="tpl-date text-[#E0E0E0] text-[14px] font-medium">27 Mar 2026</div>
                                                </div>
                                                <div class="flex justify-between items-center">
                                                    <div class="text-[#A0A0A0] text-[14px]">ID transaksi</div>
                                                    <div class="text-[#E0E0E0] text-[14px] flex items-center gap-1.5 font-medium">
                                                        <span class="tpl-trx-line">A120260327...</span>
                                                        <div class="w-[14px] h-[14px] flex items-center justify-center opacity-80" style="line-height:0;">
                                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#A0A0A0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="dotted-divider-dark my-4 border-t-[1px] border-dashed border-[#2A2C31]"></div>
                                            
                                            <!-- Jumlah -->
                                            <div class="flex justify-between items-center mb-4 text-[#A0A0A0]">
                                                <div class="text-[14px]">Jumlah</div>
                                                <div class="tpl-amount text-[14px] text-[#E0E0E0]">Rp42.500</div>
                                            </div>
                                            
                                            <div class="dotted-divider-dark my-4 border-t-[1px] border-dashed border-[#2A2C31]"></div>
                                            
                                            <!-- Total -->
                                            <div class="flex justify-between items-center">
                                                <div class="text-white font-bold text-[15px]">Total</div>
                                                <div class="tpl-total text-white font-bold text-[15px]">Rp42.500</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Bottom Splash Dark Exact Structure -->
                                <div class="w-full z-20 rounded-t-[20px] overflow-hidden shadow-[0_-8px_20px_rgba(0,0,0,0.6)] mt-6">
                                    <!-- Banner Message -->
                                    <div class="bg-[#00B8D4] text-white text-center py-3.5 text-[13.5px] font-medium w-full relative z-20">
                                        Transfer sesama GoPay, dapet puzzle, menang miliaran!
                                        <div class="absolute -bottom-2 left-0 right-0 h-4 bg-[#00B8D4]"></div>
                                    </div>
                                    <!-- Dark Sheet Background -->
                                    <div class="bg-[#22252A] pt-4 pb-6 px-5 relative z-30 rounded-t-[16px] mt-[-8px] shadow-lg w-full">
                                        <div class="max-w-md mx-auto">
                                            <!-- Share Button -->
                                            <button class="w-full bg-[#181F28] border border-[#27303B] rounded-[24px] py-1.5 flex items-center justify-center transition mb-2 shadow-sm">
                                                <div class="bg-[#00AED6] rounded-full w-[36px] h-[36px] flex items-center justify-center shadow-lg" style="line-height:0;">
                                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:block; margin:auto;"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line></svg>
                                                </div>
                                            </button>
                                            <div class="text-center text-[#9E9E9E] text-[13px] font-medium mt-1">
                                                Bagi bukti bayar
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ══════════════════════════════════════════════════
                             TPL 4: BCA LOCKSCREEN NOTIFICATION
                             ══════════════════════════════════════════════════ -->
                        <div class="receipt-wrapper w-full flex justify-center hidden" id="tpl_bca">
                            <div id="wrapper_bca" class="w-full max-w-[420px] mx-auto relative select-none overflow-hidden flex flex-col justify-between" 
                                 style="min-height: 890px; aspect-ratio: 453 / 1024; background: url('<?= base_url('assets/proof/bg_bca_fully_clean.jpg') ?>') no-repeat center top; background-size: cover; font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;">
                                
                                <!-- Hardware Cutouts -->
                                <div class="phone-dynamic-island" id="bca_island_iphone" style="display: none;">
                                    <div class="dynamic-island-cam"></div>
                                    <div class="dynamic-island-sensor"></div>
                                </div>
                                <div class="phone-classic-notch" id="bca_island_notch" style="display: none;">
                                    <div class="classic-notch-speaker"></div>
                                    <div class="classic-notch-cam"></div>
                                </div>
                                <div class="phone-punch-hole" id="bca_island_punchhole" style="display: none;"></div>
                                <div class="phone-waterdrop" id="bca_island_waterdrop" style="display: none;">
                                    <div class="phone-waterdrop-cam"></div>
                                </div>

                                <!-- Dynamic Status Bar -->
                                <div class="dynamic-status-bar" id="bca_dynamic_status_bar" style="display: none;" title="Klik untuk acak status bar (Baterai & Sinyal)" onclick="randomizeStatusBarValues(); updatePreview();">
                                    <div class="flex items-center gap-1.5" id="bca_bar_left"></div>
                                    <div class="flex items-center gap-1.5" id="bca_bar_right"></div>
                                </div>

                                <!-- Top Area: Lockscreen Clock & Notification Card -->
                                <div class="w-full px-4 flex flex-col items-start relative z-20 box-border" id="bca_content_top" style="padding-top: 56px;">
                                    
                                    <!-- Lockscreen Clock & Date: iOS Centered Mode -->
                                    <div id="bca_header_ios" class="w-full flex flex-col items-center justify-center text-center" style="display: none; margin-bottom: 22px;">
                                        <div class="text-white/80 mb-1" style="line-height: 0;">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:block; margin:auto;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        </div>
                                        <div id="bca_preview_ios_date" class="text-white text-[14px] font-semibold tracking-wide drop-shadow-md text-center">Selasa, 15 September</div>
                                        <div id="bca_preview_ios_time" class="text-white text-[58px] font-bold tracking-tight leading-none drop-shadow-md text-center mt-1">09.44</div>
                                    </div>

                                    <!-- Lockscreen Clock & Date: Android Mode -->
                                    <div id="bca_header_android" class="w-full flex items-center gap-2.5 pl-1" style="margin-bottom: 20px;">
                                        <div id="bca_preview_time" class="text-white text-[44px] font-bold tracking-tight leading-none drop-shadow-sm">09.44</div>
                                        <div class="flex flex-col text-white text-[13.5px] font-bold leading-tight pl-1 drop-shadow-sm">
                                            <span id="bca_preview_day">Sel</span>
                                            <span id="bca_preview_date" class="mt-0.5">Sep 15</span>
                                        </div>
                                    </div>

                                    <!-- BCA Notification Card -->
                                    <div class="w-full bg-[#DCE5EB]/95 backdrop-blur-md rounded-[22px] p-4 shadow-xl border border-white/40 box-border overflow-hidden" style="margin-top: 0;">
                                        <!-- Card Header -->
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2.5">
                                                <img src="<?= base_url('assets/proof/mybca_notif_icon.png') ?>" alt="myBCA" class="w-[32px] h-[32px] rounded-[9px] object-cover shadow-sm flex-shrink-0">
                                                <div class="flex items-center gap-2 text-[12.5px]">
                                                    <span class="font-bold text-slate-800 tracking-tight">myBCA</span>
                                                    <span class="text-slate-500 font-normal" id="bca_preview_notif_time">sekarang</span>
                                                </div>
                                            </div>
                                            <div class="text-slate-400 pr-1" style="line-height: 0;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:block;"><polyline points="18 15 12 9 6 15"></polyline></svg>
                                            </div>
                                        </div>

                                        <!-- Card Body -->
                                        <div class="mt-1.5 pl-[42px] pr-1">
                                            <div class="font-bold text-[13px] text-slate-900 leading-snug">Catatan Finansial</div>
                                            <div class="text-[12px] text-slate-800 mt-1 leading-[1.45]" id="bca_preview_body" style="word-break: break-all; overflow-wrap: anywhere; white-space: normal;">
                                                Pemasukan sebesar <span class="font-semibold text-slate-900" id="bca_preview_amount">IDR 24,000.00</span> dari <span id="bca_preview_sender">MANDIRI BI-FAST</span> di kategori <span id="bca_preview_category">Transfer Rekening</span>.
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Dynamic Bottom Controls -->
                                <!-- iOS Bottom Container -->
                                <div class="ios-bottom-container" id="bca_bottom_ios" style="display: none;">
                                    <div class="ios-actions-row">
                                        <div class="ios-circle-btn" style="line-height: 0;">
                                            <svg width="19" height="19" viewBox="0 0 24 24" fill="currentColor" style="display:block; margin:auto;"><path d="M13 2L3 14h8l-1 8 11-12h-8l1-8z"/></svg>
                                        </div>
                                        <div class="ios-circle-btn" style="line-height: 0;">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block; margin:auto;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                        </div>
                                    </div>
                                    <div class="ios-home-indicator"></div>
                                </div>

                                <!-- Android Bottom Container -->
                                <div class="android-bottom-container" id="bca_bottom_android" style="display: none;">
                                    <div class="android-trash-circle" style="line-height: 0;">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block; margin:auto;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                    </div>
                                    <div class="android-nav-bar">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.75;"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                                        <div class="w-[12px] h-[12px] rounded-[3px] border-2 border-white/70"></div>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.75;"><polyline points="15 18 9 12 15 6"></polyline></svg>
                                    </div>
                                </div>

                                <!-- Empty Bottom Spacer (keeps background trash icon and home indicator visible on default wallpaper) -->
                                <div class="w-full h-36 relative z-10" id="bca_default_spacer" onclick="exitFs()"></div>
                            </div>
                        </div>

                        <!-- ══════════════════════════════════════════════════
                             TPL 5: BRI LOCKSCREEN NOTIFICATION
                             ══════════════════════════════════════════════════ -->
                        <div class="receipt-wrapper w-full flex justify-center hidden" id="tpl_bri">
                            <div id="wrapper_bri" class="w-full max-w-[420px] mx-auto relative select-none overflow-hidden flex flex-col justify-between"
                                 style="min-height: 890px; aspect-ratio: 453 / 1024; background: url('<?= base_url('assets/proof/bg_bri_fully_clean.jpg') ?>') no-repeat center top; background-size: cover; font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;">
                                
                                <!-- Hardware Cutouts -->
                                <div class="phone-dynamic-island" id="bri_island_iphone" style="display: none;">
                                    <div class="dynamic-island-cam"></div>
                                    <div class="dynamic-island-sensor"></div>
                                </div>
                                <div class="phone-classic-notch" id="bri_island_notch" style="display: none;">
                                    <div class="classic-notch-speaker"></div>
                                    <div class="classic-notch-cam"></div>
                                </div>
                                <div class="phone-punch-hole" id="bri_island_punchhole" style="display: none;"></div>
                                <div class="phone-waterdrop" id="bri_island_waterdrop" style="display: none;">
                                    <div class="phone-waterdrop-cam"></div>
                                </div>

                                <!-- Dynamic Status Bar -->
                                <div class="dynamic-status-bar" id="bri_dynamic_status_bar" style="display: none;" title="Klik untuk acak status bar (Baterai & Sinyal)" onclick="randomizeStatusBarValues(); updatePreview();">
                                    <div class="flex items-center gap-1.5" id="bri_bar_left"></div>
                                    <div class="flex items-center gap-1.5" id="bri_bar_right"></div>
                                </div>

                                <!-- Top Area: Lockscreen Clock & Notification Card -->
                                <div class="w-full px-4 flex flex-col relative z-20 box-border" id="bri_content_top" style="padding-top: 56px;">
                                    
                                    <!-- Lockscreen Clock & Date: iOS Centered Mode -->
                                    <div id="bri_header_ios" class="w-full flex flex-col items-center justify-center text-center" style="display: none; margin-bottom: 22px;">
                                        <div class="text-white/80 mb-1" style="line-height: 0;">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:block; margin:auto;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        </div>
                                        <div id="bri_preview_ios_date" class="text-white text-[14px] font-semibold tracking-wide drop-shadow-md text-center">Selasa, 15 September</div>
                                        <div id="bri_preview_ios_time" class="text-white text-[58px] font-bold tracking-tight leading-none drop-shadow-md text-center mt-1">09.43</div>
                                    </div>

                                    <!-- Lockscreen Clock & Date with Bell Icon: Android Mode -->
                                    <div id="bri_header_android" class="w-full flex items-start justify-between px-1" style="margin-bottom: 20px;">
                                        <div>
                                            <div id="bri_preview_time" class="text-[#202124] text-[40px] font-bold tracking-tight leading-none">09.43</div>
                                            <div id="bri_preview_date" class="text-[#475569] text-[13px] font-medium mt-1">Sel, 15 Sep</div>
                                        </div>
                                        <!-- Bell with gear icon -->
                                        <div class="text-[#374151] pt-1 pr-1 opacity-85" id="bri_header_bell">
                                            <div class="relative w-[22px] h-[22px] flex items-center justify-center">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                                                <div class="absolute -bottom-0.5 -right-0.5 bg-[#dcdcdc] rounded-full p-[1px] flex items-center justify-center">
                                                    <svg width="9" height="9" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- BRImo Notification Card -->
                                    <div class="w-full bg-[#ECEFEA]/95 backdrop-blur-md rounded-[22px] p-4 shadow-sm border border-black/5 box-border overflow-hidden" style="margin-top: 0;">
                                        <!-- Card Header -->
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2.5">
                                                <img src="<?= base_url('assets/proof/brimo_notif_icon.png') ?>" alt="BRImo" class="w-[30px] h-[30px] rounded-[8px] object-cover shadow-xs flex-shrink-0">
                                                <div class="flex items-center gap-1.5 text-[12.5px]">
                                                    <span class="font-bold text-slate-800">BRImo</span>
                                                    <span class="text-slate-400 text-xs">•</span>
                                                    <span class="text-slate-600 font-normal" id="bri_preview_notif_time">sekarang</span>
                                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-slate-400 ml-0.5"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                                                </div>
                                            </div>
                                            <div class="text-slate-700 pr-1" style="line-height: 0;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:block;"><polyline points="18 15 12 9 6 15"></polyline></svg>
                                            </div>
                                        </div>

                                        <!-- Card Body -->
                                        <div class="mt-1.5 pl-[40px] pr-1">
                                            <div class="font-bold text-[13px] text-slate-900 leading-snug">BRImo</div>
                                            <div class="text-[12px] text-[#374151] mt-1 leading-[1.45]" id="bri_preview_body" style="word-break: break-all; overflow-wrap: anywhere; white-space: normal;">
                                                Sobat BRI! Dana <span class="font-semibold text-slate-900" id="bri_preview_amount">Rp2.586.000</span> masuk ke rekening <span id="bri_preview_account">777401010338506</span> pada <span id="bri_preview_trx_datetime">15/09/2026 09:43:22</span> KET.:<span id="bri_preview_ket" style="word-break: break-all; overflow-wrap: anywhere;">MANDIRI BI-FAST REF:20260915849102 BUDI SANTOSO</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Dynamic Bottom Controls -->
                                <!-- iOS Bottom Container -->
                                <div class="ios-bottom-container" id="bri_bottom_ios" style="display: none;">
                                    <div class="ios-actions-row">
                                        <div class="ios-circle-btn" style="line-height: 0;">
                                            <svg width="19" height="19" viewBox="0 0 24 24" fill="currentColor" style="display:block; margin:auto;"><path d="M13 2L3 14h8l-1 8 11-12h-8l1-8z"/></svg>
                                        </div>
                                        <div class="ios-circle-btn" style="line-height: 0;">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block; margin:auto;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                        </div>
                                    </div>
                                    <div class="ios-home-indicator"></div>
                                </div>

                                <!-- Android Bottom Container -->
                                <div class="android-bottom-container" id="bri_bottom_android" style="display: none;">
                                    <div class="android-trash-circle" style="line-height: 0;">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block; margin:auto;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                    </div>
                                    <div class="android-nav-bar">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.75;"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                                        <div class="w-[12px] h-[12px] rounded-[3px] border-2 border-white/70"></div>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.75;"><polyline points="15 18 9 12 15 6"></polyline></svg>
                                    </div>
                                </div>

                                <!-- Empty Bottom Spacer (keeps background trash icon and Android 3-button navigation visible on default wallpaper) -->
                                <div class="w-full h-36 relative z-10" id="bri_default_spacer" onclick="exitFs()"></div>
                            </div>
                        </div>

                        <!-- ══════════════════════════════════════════════════
                             TPL 6: DANA LOCKSCREEN NOTIFICATION
                             ══════════════════════════════════════════════════ -->
                        <div class="receipt-wrapper w-full flex justify-center hidden" id="tpl_dana_notif">
                            <div id="wrapper_dana_notif" class="w-full max-w-[420px] mx-auto relative select-none overflow-hidden flex flex-col justify-between"
                                 style="min-height: 890px; aspect-ratio: 453 / 1024; background: url('<?= base_url('assets/proof/bg_dana_fully_clean.jpg') ?>') no-repeat center top; background-size: cover; font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;">
                                
                                <!-- Hardware Cutouts -->
                                <div class="phone-dynamic-island" id="dana_notif_island_iphone" style="display: none;">
                                    <div class="dynamic-island-cam"></div>
                                    <div class="dynamic-island-sensor"></div>
                                </div>
                                <div class="phone-classic-notch" id="dana_notif_island_notch" style="display: none;">
                                    <div class="classic-notch-speaker"></div>
                                    <div class="classic-notch-cam"></div>
                                </div>
                                <div class="phone-punch-hole" id="dana_notif_island_punchhole" style="display: none;"></div>
                                <div class="phone-waterdrop" id="dana_notif_island_waterdrop" style="display: none;">
                                    <div class="phone-waterdrop-cam"></div>
                                </div>

                                <!-- Dynamic Status Bar -->
                                <div class="dynamic-status-bar" id="dana_notif_dynamic_status_bar" style="display: none;" title="Klik untuk acak status bar (Baterai & Sinyal)" onclick="randomizeStatusBarValues(); updatePreview();">
                                    <div class="flex items-center gap-1.5" id="dana_notif_bar_left"></div>
                                    <div class="flex items-center gap-1.5" id="dana_notif_bar_right"></div>
                                </div>

                                <!-- Top Area: Lockscreen Clock & Notification Card -->
                                <div class="w-full px-4 flex flex-col relative z-20 box-border" id="dana_notif_content_top" style="padding-top: 56px;">
                                    
                                    <!-- Lockscreen Clock & Date: iOS Centered Mode -->
                                    <div id="dana_notif_header_ios" class="w-full flex flex-col items-center justify-center text-center" style="display: none; margin-bottom: 22px;">
                                        <div class="text-white/80 mb-1" style="line-height: 0;">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        </div>
                                        <div id="dana_notif_preview_ios_date" class="text-white text-[14px] font-semibold tracking-wide drop-shadow-md text-center">Minggu, 6 September</div>
                                        <div id="dana_notif_preview_ios_time" class="text-white text-[58px] font-bold tracking-tight leading-none drop-shadow-md text-center mt-1">17.29</div>
                                    </div>

                                    <!-- Lockscreen Clock & Date with Bell Icon: Android Mode -->
                                    <div id="dana_notif_header_android" class="w-full flex items-start justify-between px-1" style="margin-bottom: 8px;">
                                        <div class="flex items-baseline gap-2">
                                            <div id="dana_notif_preview_time" class="text-white text-[44px] font-bold tracking-tight leading-none drop-shadow-sm">17.29</div>
                                            <div class="flex flex-col text-white text-[12.5px] font-medium leading-tight drop-shadow-sm">
                                                <span id="dana_notif_preview_day">Min</span>
                                                <span id="dana_notif_preview_date" class="mt-0.5">Sep 6</span>
                                            </div>
                                        </div>
                                        <!-- Bell with gear icon -->
                                        <div class="text-white pt-1 pr-1 opacity-85" id="dana_notif_header_bell">
                                            <div class="relative w-[22px] h-[22px] flex items-center justify-center">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                                                <div class="absolute -bottom-0.5 -right-0.5 bg-white/30 backdrop-blur-sm rounded-full p-[1px] flex items-center justify-center">
                                                    <svg width="9" height="9" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Senyap Subtitle -->
                                    <div id="dana_notif_senyap_row" class="w-full flex items-center justify-between px-1 mb-2 text-white/80 text-[12px]">
                                        <span class="font-medium">Senyap</span>
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="opacity-80"><polyline points="18 15 12 9 6 15"></polyline></svg>
                                    </div>

                                    <!-- DANA NOTIFICATION CARD (Exact Replica of media_1789487319325.jpg) -->
                                    <div class="w-full bg-[#2E333C]/95 backdrop-blur-md rounded-[22px] p-4 shadow-lg border border-white/10 box-border overflow-hidden relative">
                                        <div class="flex items-start justify-between">
                                            <!-- Left Column: Icon + Text -->
                                            <div class="flex items-start gap-3 flex-1 pr-1">
                                                <!-- DANA App Icon -->
                                                <img src="<?= base_url('assets/proof/dana_notif_icon.png') ?>" alt="DANA" class="w-[34px] h-[34px] rounded-[10px] object-cover shadow-sm flex-shrink-0 mt-0.5">
                                                <div class="flex-1">
                                                    <!-- App name & time with bullet dot -->
                                                    <div class="flex items-center gap-1.5 text-[12.5px] leading-tight">
                                                        <span class="font-normal text-slate-300">DANA</span>
                                                        <span class="text-slate-400 text-[11px]">•</span>
                                                        <span class="text-slate-400 font-normal" id="dana_notif_preview_time_ago">sekarang</span>
                                                    </div>
                                                    <!-- Notification Title -->
                                                    <div class="font-bold text-[13px] text-white mt-1 leading-snug">DANA</div>
                                                    <!-- Notification Body -->
                                                    <div class="text-[12px] text-slate-200 mt-0.5 leading-[1.4]" id="dana_notif_preview_body" style="word-break: break-word; overflow-wrap: anywhere;">
                                                        Berhasil top up saldo <span class="font-normal text-white" id="dana_notif_preview_amount">Rp91.269</span> dari <span id="dana_notif_preview_sender">MITRA DIGITAL</span>. Lihat detailnya di sini!
                                                    </div>
                                                </div>
                                            </div>
                                            <!-- Right Column: Chevron & Orange Avatar Badge -->
                                            <div class="flex flex-col items-end justify-between self-stretch pl-1">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5"><polyline points="18 15 12 9 6 15"></polyline></svg>
                                                <!-- Orange Avatar Badge -->
                                                <img src="<?= base_url('assets/proof/dana_orange_badge.png') ?>" alt="Transfer" class="w-[36px] h-[36px] rounded-full object-cover shadow-sm mt-3">
                                            </div>
                                        </div>
                                    </div>

                                </div>

                                <!-- Dynamic Bottom Controls -->
                                <!-- iOS Bottom Container -->
                                <div class="ios-bottom-container" id="dana_notif_bottom_ios" style="display: none;">
                                    <div class="ios-actions-row">
                                        <div class="ios-circle-btn" style="line-height: 0;">
                                            <svg width="19" height="19" viewBox="0 0 24 24" fill="currentColor" style="display:block; margin:auto;"><path d="M13 2L3 14h8l-1 8 11-12h-8l1-8z"/></svg>
                                        </div>
                                        <div class="ios-circle-btn" style="line-height: 0;">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block; margin:auto;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                        </div>
                                    </div>
                                    <div class="ios-home-indicator"></div>
                                </div>

                                <!-- Android Bottom Container -->
                                <div class="android-bottom-container" id="dana_notif_bottom_android" style="display: none;">
                                    <div class="android-trash-circle" style="line-height: 0;">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block; margin:auto;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                    </div>
                                    <div class="android-nav-bar">
                                        <div class="w-[100px] h-[3.5px] rounded-full bg-white/70"></div>
                                    </div>
                                </div>

                                <!-- Empty Bottom Spacer -->
                                <div class="w-full h-36 relative z-10" id="dana_notif_default_spacer" onclick="exitFs()"></div>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

        </div>

    </div>

    <!-- ══ JAVASCRIPT SYSTEM LOGIC (PRESERVED & EXPANDED) ══ -->
    <script>
        // Global Device & Wallpaper State
        let currentPhoneModel = 'oppo'; // 'iphone', 'samsung', 'xiaomi', 'oppo', etc.
        let currentWallpaper = 'default'; // 'default', 'sunset', 'ocean', 'obsidian', 'twilight', 'emerald', 'cyberpunk'
        let detectedUserDevice = 'oppo';

        // ══ REALISTIC RANDOMIZED STATUS BAR STATE ══
        let phoneStatusBarData = {
            batteryPct: 88,
            signalBars: 4,
            netTypeIos: '5G',
            netTypeAndroid: '5G',
            oppoSpeed: '468 K/s',
            oppoSim1: 'Telkomsel',
            oppoSim2: 'TELKOMSEL',
            showVoLTE: true,
            showWifi: true
        };

        function randomizeStatusBarValues() {
            // Random realistic battery percentage: 52% - 99%
            phoneStatusBarData.batteryPct = Math.floor(Math.random() * 48) + 52;
            // 75% chance for 4 bars, 25% chance for 3 bars
            phoneStatusBarData.signalBars = (Math.random() > 0.25) ? 4 : 3;
            
            const iosNets = ['5G', '5G', '5G', 'LTE', '4G'];
            phoneStatusBarData.netTypeIos = iosNets[Math.floor(Math.random() * iosNets.length)];
            
            const androidNets = ['5G', '5G', '4G+', '4G+', '4.5G'];
            phoneStatusBarData.netTypeAndroid = androidNets[Math.floor(Math.random() * androidNets.length)];
            
            const carriers = [
                ['Telkomsel', 'TELKOMSEL'],
                ['Indosat Ooredoo', 'INDOSAT'],
                ['XL Axiata', 'XL AXIATA'],
                ['Tri Indonesia', '3']
            ];
            const chosenCarrier = carriers[Math.floor(Math.random() * carriers.length)];
            phoneStatusBarData.oppoSim1 = chosenCarrier[0];
            phoneStatusBarData.oppoSim2 = chosenCarrier[1];
            
            if (Math.random() > 0.3) {
                phoneStatusBarData.oppoSpeed = Math.floor(Math.random() * 750 + 120) + ' K/s';
            } else {
                phoneStatusBarData.oppoSpeed = (Math.random() * 2.2 + 0.5).toFixed(1) + ' M/s';
            }
            
            phoneStatusBarData.showVoLTE = Math.random() > 0.15;
            phoneStatusBarData.showWifi = true;
        }

        // Initialize status bar data with randomized values on start
        randomizeStatusBarValues();

        const wallpaperStyles = {
            default: {
                name: "Bawaan Asli"
            },
            sunset: {
                gradient: "linear-gradient(180deg, #1E1B4B 0%, #4338CA 30%, #BE185D 65%, #F59E0B 100%)",
                name: "Sunset Aura"
            },
            ocean: {
                gradient: "linear-gradient(180deg, #020617 0%, #0F172A 25%, #0369A1 65%, #06B6D4 100%)",
                name: "Deep Ocean"
            },
            obsidian: {
                gradient: "linear-gradient(180deg, #07090E 0%, #111827 50%, #1F2937 100%)",
                name: "Obsidian Dark"
            },
            twilight: {
                gradient: "linear-gradient(180deg, #18052E 0%, #3B0764 35%, #701A75 75%, #A21CAF 100%)",
                name: "Twilight Purple"
            },
            emerald: {
                gradient: "linear-gradient(180deg, #022C22 0%, #064E3B 35%, #047857 70%, #10B981 100%)",
                name: "Emerald Forest"
            },
            cyberpunk: {
                gradient: "linear-gradient(180deg, #0F172A 0%, #312E81 40%, #701A75 75%, #EC4899 100%)",
                name: "Cyber Neon"
            }
        };

        function formatCurrency(num) {
            return 'Rp' + parseInt(num || 0).toLocaleString('id-ID');
        }

        function formatBcaCurrency(num) {
            const val = parseFloat(num || 0);
            return 'IDR ' + val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function formatBriCurrency(num) {
            return 'Rp' + parseInt(num || 0).toLocaleString('id-ID');
        }

        // ══ DEVICE AUTO-DETECTION ENGINE ══
        function detectAndApplyUserDevice() {
            const ua = navigator.userAgent || '';
            let model = 'oppo';
            let label = 'Auto: Android';

            if (/iPhone1[4-9]|iPhone[2-9]/i.test(ua)) {
                model = 'iphone';
                label = 'Auto: iPhone 16 Pro';
            } else if (/iPhone/i.test(ua)) {
                model = 'iphone_notch';
                label = 'Auto: iPhone 13/14';
            } else if (/Samsung|SM-|Galaxy/i.test(ua)) {
                model = 'samsung';
                label = 'Auto: Samsung Galaxy';
            } else if (/Xiaomi|Redmi|POCO/i.test(ua)) {
                model = 'xiaomi';
                label = 'Auto: Xiaomi / POCO';
            } else if (/vivo|V2/i.test(ua)) {
                model = 'vivo';
                label = 'Auto: Vivo V-Series';
            } else if (/Realme|RMX/i.test(ua)) {
                model = 'realme';
                label = 'Auto: Realme GT';
            } else if (/Infinix|TECNO|X6/i.test(ua)) {
                model = 'infinix';
                label = 'Auto: Infinix / Tecno';
            } else if (/Pixel/i.test(ua)) {
                model = 'pixel';
                label = 'Auto: Google Pixel';
            } else if (/OPPO|CPH/i.test(ua)) {
                model = 'oppo';
                label = 'Auto: Oppo Reno';
            } else {
                model = 'oppo';
                label = 'Auto: Ponsel Anda';
            }

            detectedUserDevice = model;
            const txtEl = document.getElementById('txtAutoDetect');
            if (txtEl) txtEl.textContent = label;

            selectPhoneModel(model, false);
        }

        function selectPhoneModel(modelName, triggerUpdate = true) {
            currentPhoneModel = modelName;
            randomizeStatusBarValues();
            document.querySelectorAll('.btn-phone-model').forEach(btn => {
                if (btn.getAttribute('data-model') === modelName) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            if (triggerUpdate) {
                updatePreview();
            }
        }

        function selectWallpaper(wpKey) {
            currentWallpaper = wpKey;
            document.querySelectorAll('.btn-wp-chip').forEach(btn => {
                if (btn.getAttribute('data-wp') === wpKey) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            const wpMeta = wallpaperStyles[wpKey];
            const lbl = document.getElementById('lblWpName');
            if (lbl && wpMeta) lbl.textContent = wpMeta.name;

            updatePreview();
        }

        // ══ RANDOM ACCOUNT GENERATORS ══
        function generateRandomDanaAccount(operator = 'random') {
            const operatorPrefixes = {
                indosat: ['0857', '0856', '0858'],
                telkomsel: ['0812', '0813', '0821', '0822', '0852', '0853'],
                xl: ['0877', '0878', '0818', '0819', '0831', '0838'],
                tri: ['0895', '0896', '0897', '0898']
            };

            let prefixList = [];
            if (operator && operatorPrefixes[operator]) {
                prefixList = operatorPrefixes[operator];
            } else {
                prefixList = [
                    '0857', '0856', '0858', // Indosat
                    '0812', '0813', '0821', '0822', '0852', // Telkomsel
                    '0877', '0878', '0818', '0819', '0838', // XL/Axis
                    '0895', '0896', '0897' // Tri
                ];
            }

            const prefix = prefixList[Math.floor(Math.random() * prefixList.length)];
            let last4 = '';
            for (let i = 0; i < 4; i++) {
                last4 += Math.floor(Math.random() * 10);
            }
            return `${prefix}•••${last4}`;
        }

        function maskDanaPhoneNumber(raw) {
            if (!raw) return '';
            const cleaned = raw.trim();
            // If already has bullet dots (•) or asterisks (*), keep as is
            if (cleaned.includes('•') || cleaned.includes('*')) {
                return cleaned;
            }
            // Strip any non-digits
            let digits = cleaned.replace(/\D/g, '');
            // If starts with 62, replace with 0
            if (digits.startsWith('62')) {
                digits = '0' + digits.slice(2);
            }
            // If at least 8 digits (typical Indonesian phone number), mask the middle
            if (digits.length >= 8) {
                const pre = digits.slice(0, 4);
                const post = digits.slice(-4);
                return `${pre}•••${post}`;
            }
            return cleaned;
        }

        function formatDanaReceiverInput(inputEl) {
            if (inputEl && inputEl.value) {
                inputEl.value = maskDanaPhoneNumber(inputEl.value);
            }
            updatePreview();
        }

        function handleDanaReceiverInput(inputEl) {
            updatePreview();
        }

        function randomizeDanaAccount(operator = 'random') {
            const el = document.getElementById('danaReceiver');
            if (el) {
                el.value = generateRandomDanaAccount(operator);
            }
            updatePreview();
        }

        function generateRandomBriAccount() {
            const prefixes = ['7774', '0021', '1056', '3214', '5829', '0206', '0341', '0112'];
            const prefix = prefixes[Math.floor(Math.random() * prefixes.length)];
            let rest = '';
            for (let i = 0; i < 11; i++) {
                rest += Math.floor(Math.random() * 10);
            }
            return prefix + rest;
        }

        function randomizeBriAccount() {
            const el = document.getElementById('briAccount');
            if (el) el.value = generateRandomBriAccount();
            updatePreview();
        }

        function generateRandomBcaAccount() {
            const prefixes = ['8210', '5415', '0351', '6820', '7150', '0142', '2060'];
            const prefix = prefixes[Math.floor(Math.random() * prefixes.length)];
            let rest = '';
            for (let i = 0; i < 6; i++) {
                rest += Math.floor(Math.random() * 10);
            }
            return prefix + rest;
        }

        function randomizeBcaAccount() {
            randomizeBcaSender();
        }

        // ══ MANDIRI KET & SENDER GENERATORS ══
        function generateMandiriBriKet(type = 'random') {
            const names = [
                'BUDI SANTOSO', 'DIMAS PRASETYO', 'AHMAD FAUZI', 'EKO WAHYUDI', 
                'HENDRA KURNIAWAN', 'RIZKY PRATAMA', 'ANDI WIJAYA', 'BAMBANG SUPRAPTO', 
                'ILHAM RAMADHAN', 'WAHYU HIDAYAT', 'SETIAWAN', 'MUHAMMAD RIFKI'
            ];
            const name = names[Math.floor(Math.random() * names.length)];
            const now = new Date();
            const yr = now.getFullYear();
            const mo = String(now.getMonth() + 1).padStart(2, '0');
            const dy = String(now.getDate()).padStart(2, '0');
            const rnd6 = Math.floor(100000 + Math.random() * 900000);

            const options = ['bifast', 'trf', 'livin'];
            const chosenType = (type === 'random') ? options[Math.floor(Math.random() * options.length)] : type;

            if (chosenType === 'bifast') {
                return `MANDIRI BI-FAST REF:${yr}${mo}${dy}${rnd6} ${name}`;
            } else if (chosenType === 'trf') {
                return `TRF MANDIRI ${yr}${mo}${dy}${rnd6} ${name}`;
            } else {
                return `MANDIRILIVIN${yr}${mo}${dy}0353${rnd6}${name.replace(/\s+/g, '')}`;
            }
        }

        function setMandiriBriKet(type) {
            const el = document.getElementById('briKet');
            if (el) el.value = generateMandiriBriKet(type);
            updatePreview();
        }

        function setMandiriBcaSender(val) {
            const el = document.getElementById('bcaSender');
            if (el) el.value = val;
            updatePreview();
        }

        function randomizeBcaSender() {
            const senders = [
                'MANDIRI BI-FAST',
                'BANK MANDIRI',
                'TRF MANDIRI BI-FAST',
                'MANDIRI LIVIN',
                'MANDIRI - **DI **SAN**SO',
                'MANDIRI - **RI **PRA**MA',
                'MANDIRI - **MAD **FAU**I'
            ];
            const s = senders[Math.floor(Math.random() * senders.length)];
            setMandiriBcaSender(s);
        }

        function setNowTrxBri() {
            const now = new Date();
            const dy = String(now.getDate()).padStart(2, '0');
            const mo = String(now.getMonth() + 1).padStart(2, '0');
            const yr = now.getFullYear();
            const hr = String(now.getHours()).padStart(2, '0');
            const mn = String(now.getMinutes()).padStart(2, '0');
            const sc = String(now.getSeconds()).padStart(2, '0');
            const el = document.getElementById('briTrxDateTime');
            if (el) el.value = `${dy}/${mo}/${yr} ${hr}:${mn}:${sc}`;
            updatePreview();
        }

        // ══ MASTER RANDOMIZER & TIME SYNC ══
        function randomizeAllAndSetNow() {
            randomizeStatusBarValues();
            refreshTimeNow();
            setNowTrxBri();
            randomizeBriAccount();
            setMandiriBriKet('random');
            randomizeBcaSender();
            randomizeDanaAccount();
            const bcaNotif = document.getElementById('bcaNotifTime');
            if (bcaNotif) bcaNotif.value = 'sekarang';
            const briNotif = document.getElementById('briNotifTime');
            if (briNotif) briNotif.value = 'sekarang';
            updatePreview();
        }

        function selectTemplate(tplVal) {
            const select = document.getElementById('templateType');
            select.value = tplVal;
            syncTemplateChips();
            updateFormDisplay();
            updatePreview();
        }

        function syncTemplateChips() {
            const currentTpl = document.getElementById('templateType').value;
            document.querySelectorAll('.tpl-chip-card').forEach(card => {
                if (card.getAttribute('data-tpl') === currentTpl) {
                    card.classList.add('active');
                } else {
                    card.classList.remove('active');
                }
            });
        }

        function updateFormDisplay() {
            const tpl = document.getElementById('templateType').value;
            // Hide all dedicated forms
            document.querySelectorAll('.form-group-wrapper').forEach(el => {
                el.classList.add('hidden');
                el.style.display = 'none';
            });

            const lockCustomizer = document.getElementById('lockscreen_customizer');

            if (tpl === 'dana_notif') {
                const f = document.getElementById('form_dana_notif');
                if (f) { f.classList.remove('hidden'); f.style.display = 'block'; }
                if (lockCustomizer) { lockCustomizer.classList.remove('hidden'); lockCustomizer.style.display = 'block'; }
                const devBox = document.getElementById('deviceSelectorBox');
                if (devBox) devBox.style.display = 'block';
            } else if (tpl.startsWith('dana')) {
                const f = document.getElementById('form_dana');
                if (f) { f.classList.remove('hidden'); f.style.display = 'block'; }
                const lbl = document.getElementById('lblDanaMerchant');
                if (lbl) lbl.textContent = (tpl === 'dana_receive') ? 'Nama Pengirim' : 'Nama Penerima (Merchant)';
                if (lockCustomizer) { lockCustomizer.classList.add('hidden'); lockCustomizer.style.display = 'none'; }
            } else if (tpl.startsWith('gopay_light')) {
                const f = document.getElementById('form_gopay_light');
                if (f) { f.classList.remove('hidden'); f.style.display = 'block'; }
                const lbl = document.getElementById('lblGopayLightName');
                if (lbl) lbl.textContent = (tpl === 'gopay_light_receive') ? 'Diterima dari' : 'Pembayaran ke';
                if (lockCustomizer) { lockCustomizer.classList.add('hidden'); lockCustomizer.style.display = 'none'; }
            } else if (tpl.startsWith('gopay_dark')) {
                const f = document.getElementById('form_gopay_dark');
                if (f) { f.classList.remove('hidden'); f.style.display = 'block'; }
                if (lockCustomizer) { lockCustomizer.classList.add('hidden'); lockCustomizer.style.display = 'none'; }
            } else if (tpl === 'bca_notif') {
                const f = document.getElementById('form_bca');
                if (f) { f.classList.remove('hidden'); f.style.display = 'block'; }
                if (lockCustomizer) { lockCustomizer.classList.remove('hidden'); lockCustomizer.style.display = 'block'; }
                const devBox = document.getElementById('deviceSelectorBox');
                if (devBox) devBox.style.display = 'block';
            } else if (tpl === 'bri_notif') {
                const f = document.getElementById('form_bri');
                if (f) { f.classList.remove('hidden'); f.style.display = 'block'; }
                if (lockCustomizer) { lockCustomizer.classList.remove('hidden'); lockCustomizer.style.display = 'block'; }
                const devBox = document.getElementById('deviceSelectorBox');
                if (devBox) devBox.style.display = 'block';
            }
        }

        function renderStatusBar(bankPrefix, timeStr) {
            const leftEl = document.getElementById(bankPrefix + '_bar_left');
            const rightEl = document.getElementById(bankPrefix + '_bar_right');
            if (!leftEl || !rightEl) return;

            const bat = phoneStatusBarData.batteryPct;
            const bars = phoneStatusBarData.signalBars;
            const netIos = phoneStatusBarData.netTypeIos;
            const netAndroid = phoneStatusBarData.netTypeAndroid;
            const oppoSpeed = phoneStatusBarData.oppoSpeed;

            const wifiSvg = `<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" style="display:inline-block; vertical-align:middle;"><path d="M12 18c.83 0 1.5-.67 1.5-1.5s-.67-1.5-1.5-1.5-1.5.67-1.5 1.5.67 1.5 1.5 1.5zm-4.24-4.24l1.41 1.41C10.05 14.29 10.98 14 12 14s1.95.29 2.83.88l1.41-1.41C15.01 12.56 13.56 12 12 12s-3.01.56-4.24 1.76zm-2.83-2.83l1.41 1.41C8.11 10.94 9.97 10 12 10s3.89.94 5.66 2.34l1.41-1.41C16.97 9.17 14.59 8 12 8s-4.97 1.17-7.07 2.93zM2.1 8.1l1.41 1.41C6.18 7.37 8.96 6 12 6s5.82 1.37 8.49 3.51l1.41-1.41C18.99 5.65 15.65 4 12 4s-6.99 1.65-9.9 4.1z"/></svg>`;
            const androidSignalSvg = `<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" style="display:inline-block; vertical-align:middle;"><rect x="2" y="16" width="3.5" height="6" rx="0.7"></rect><rect x="8" y="12" width="3.5" height="10" rx="0.7"></rect><rect x="14" y="7" width="3.5" height="15" rx="0.7"></rect><rect x="20" y="2" width="3.5" height="20" rx="0.7" fill-opacity="${bars < 4 ? '0.3' : '1'}"></rect></svg>`;
            
            const iosSignalHtml = `
                <div style="display: flex; align-items: flex-end; gap: 1.5px; height: 11px;">
                    <div style="width: 2.5px; height: 3px; background: #FFF; border-radius: 0.5px;"></div>
                    <div style="width: 2.5px; height: 5.5px; background: #FFF; border-radius: 0.5px;"></div>
                    <div style="width: 2.5px; height: 8px; background: #FFF; border-radius: 0.5px;"></div>
                    <div style="width: 2.5px; height: 11px; background: #FFF; border-radius: 0.5px; opacity: ${bars < 4 ? '0.28' : '1'};"></div>
                </div>
            `;
            const iosNotchSignalHtml = `
                <div style="display: flex; align-items: flex-end; gap: 1.5px; height: 10px;">
                    <div style="width: 2.5px; height: 3px; background: #FFF; border-radius: 0.5px;"></div>
                    <div style="width: 2.5px; height: 5px; background: #FFF; border-radius: 0.5px;"></div>
                    <div style="width: 2.5px; height: 7.5px; background: #FFF; border-radius: 0.5px;"></div>
                    <div style="width: 2.5px; height: 10px; background: #FFF; border-radius: 0.5px; opacity: ${bars < 4 ? '0.28' : '1'};"></div>
                </div>
            `;

            if (currentPhoneModel === 'iphone') {
                leftEl.innerHTML = `<span style="font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', sans-serif; font-weight: 700; font-size: 13.5px; letter-spacing: -0.2px;">${timeStr}</span>`;
                rightEl.innerHTML = `
                    ${iosSignalHtml}
                    <span style="font-size: 11px; font-weight: 800; letter-spacing: -0.5px; margin-left: 1px;">${netIos}</span>
                    <span style="margin-left: 2px;">${wifiSvg}</span>
                    <div class="ios-battery-pill" title="Baterai: ${bat}%">
                        <div class="ios-battery-fill" style="width: ${bat}%;"></div>
                    </div>
                `;
            } else if (currentPhoneModel === 'iphone_notch') {
                leftEl.innerHTML = `<span style="font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', sans-serif; font-weight: 700; font-size: 13px; letter-spacing: -0.2px;">${timeStr}</span>`;
                rightEl.innerHTML = `
                    ${iosNotchSignalHtml}
                    <span style="font-size: 10.5px; font-weight: 800; letter-spacing: -0.5px; margin-left: 1px;">${netIos}</span>
                    <span style="margin-left: 1px;">${wifiSvg}</span>
                    <div class="ios-battery-pill" title="Baterai: ${bat}%">
                        <div class="ios-battery-fill" style="width: ${bat}%;"></div>
                    </div>
                `;
            } else if (currentPhoneModel === 'xiaomi') {
                leftEl.innerHTML = `<span style="font-family: 'Segoe UI', system-ui, sans-serif; font-weight: 700; font-size: 13px; letter-spacing: 0.2px;">${timeStr}</span>`;
                rightEl.innerHTML = `
                    <span class="volte-badge">VoLTE</span>
                    <span>${androidSignalSvg}</span>
                    <span>${wifiSvg}</span>
                    <div class="xiaomi-battery-capsule" title="Baterai: ${bat}%">
                        <span class="xiaomi-battery-pct">${bat}</span>
                    </div>
                `;
            } else if (currentPhoneModel === 'samsung') {
                leftEl.innerHTML = `<span style="font-family: system-ui, sans-serif; font-weight: 700; font-size: 13px;">${timeStr}</span>`;
                rightEl.innerHTML = `
                    <span class="volte-badge" style="border-radius: 2px;">VoLTE</span>
                    <span>${androidSignalSvg}</span>
                    <span>${wifiSvg}</span>
                    <div class="samsung-battery-box" title="Baterai: ${bat}%">
                        <span>${bat}%</span>
                        <div class="samsung-bat-icon">
                            <div class="samsung-bat-fill" style="width: ${bat}%;"></div>
                        </div>
                    </div>
                `;
            } else if (currentPhoneModel === 'vivo') {
                leftEl.innerHTML = `<span style="font-family: system-ui, sans-serif; font-weight: 700; font-size: 13px;">${timeStr}</span>`;
                rightEl.innerHTML = `
                    <span class="vivo-hd-badge">HD</span>
                    <span style="font-size: 10px; font-weight: 800;">${netAndroid}</span>
                    <span>${androidSignalSvg}</span>
                    <span>${wifiSvg}</span>
                    <div class="vivo-battery-box" title="Baterai: ${bat}%">
                        <span>${bat}%</span>
                        <div class="samsung-bat-icon">
                            <div class="samsung-bat-fill" style="width: ${bat}%;"></div>
                        </div>
                    </div>
                `;
            } else if (currentPhoneModel === 'realme') {
                leftEl.innerHTML = `<span style="font-family: system-ui, sans-serif; font-weight: 700; font-size: 13px;">${timeStr}</span>`;
                rightEl.innerHTML = `
                    <span class="volte-badge">VoLTE</span>
                    <span style="font-size: 10px; font-weight: 800;">${netAndroid}</span>
                    <span>${androidSignalSvg}</span>
                    <span>${wifiSvg}</span>
                    <div class="samsung-battery-box" title="Baterai: ${bat}%">
                        <span>${bat}%</span>
                        <div class="samsung-bat-icon">
                            <div class="samsung-bat-fill" style="width: ${bat}%; background: #38BDF8;"></div>
                        </div>
                    </div>
                `;
            } else if (currentPhoneModel === 'infinix') {
                leftEl.innerHTML = `<span style="font-family: 'Segoe UI', sans-serif; font-weight: 700; font-size: 13px;">${timeStr}</span>`;
                rightEl.innerHTML = `
                    <span class="infinix-badge">4G+</span>
                    <span class="volte-badge">VoLTE</span>
                    <span>${androidSignalSvg}</span>
                    <span>${wifiSvg}</span>
                    <div class="samsung-battery-box" title="Baterai: ${bat}%">
                        <span>${bat}%</span>
                        <div class="samsung-bat-icon" style="border-color: #34D399;">
                            <div class="samsung-bat-fill" style="width: ${bat}%; background: #34D399;"></div>
                        </div>
                    </div>
                `;
            } else if (currentPhoneModel === 'pixel') {
                leftEl.innerHTML = `<span style="font-family: 'Product Sans', 'Roboto', system-ui, sans-serif; font-weight: 600; font-size: 13px;">${timeStr}</span>`;
                rightEl.innerHTML = `
                    <span style="font-size: 10.5px; font-weight: 700;">${netAndroid}</span>
                    <span>${androidSignalSvg}</span>
                    <span>${wifiSvg}</span>
                    <div class="pixel-battery-pill" title="Baterai: ${bat}%">
                        <div class="pixel-battery-fill" style="width: ${bat}%;"></div>
                    </div>
                    <span style="font-size: 10px; font-weight: 700; margin-left: 2px;">${bat}%</span>
                `;
            } else { // oppo
                leftEl.innerHTML = `
                    <div style="display: flex; flex-direction: column; font-size: 8.5px; font-weight: 700; line-height: 1.1; opacity: 0.9;">
                        <span>${phoneStatusBarData.oppoSim1}</span>
                        <span>${phoneStatusBarData.oppoSim2}</span>
                    </div>
                `;
                rightEl.innerHTML = `
                    <span style="font-size: 9px; font-weight: 600; opacity: 0.85;">${oppoSpeed}</span>
                    <span style="font-size: 9.5px; font-weight: 800;">${netAndroid}</span>
                    <span>${androidSignalSvg}</span>
                    <div class="xiaomi-battery-capsule" title="Baterai: ${bat}%">
                        <span class="xiaomi-battery-pct">${bat}</span>
                    </div>
                `;
            }
        }

        function updatePreview() {
            const tpl = document.getElementById('templateType').value;

            // Hide all layout wrappers
            document.querySelectorAll('.receipt-wrapper').forEach(el => {
                el.classList.add('hidden');
                el.style.display = 'none';
            });

            // Update outer phone mockup frame
            const mockupIsland = document.getElementById('mockupFrameIsland');
            const mockupNotch = document.getElementById('mockupFrameNotch');
            const mockupBezel = document.getElementById('mockupFrameBezel');
            if (mockupIsland) mockupIsland.style.display = (currentPhoneModel === 'iphone') ? 'flex' : 'none';
            if (mockupNotch) mockupNotch.style.display = (currentPhoneModel === 'iphone_notch') ? 'flex' : 'none';
            if (mockupBezel) mockupBezel.style.display = (currentPhoneModel === 'iphone' || currentPhoneModel === 'iphone_notch') ? 'none' : 'block';

            // ── DANA TEMPLATES ──
            if (tpl === 'dana_receive' || tpl === 'dana_payment') {
                const r = document.getElementById('tpl_dana');
                if (r) {
                    r.classList.remove('hidden');
                    r.style.display = 'block';
                }
                const merchant = document.getElementById('danaMerchant').value;
                const receiver = document.getElementById('danaReceiver').value;
                const amount = document.getElementById('danaAmount').value;
                const now = getCurrentDateTime();
                const date = now.date;
                const time = now.time;

                const mainTitle = document.querySelector('#tpl_dana .payment-title');
                const totLab = document.querySelector('#tpl_dana .total-label');
                const logoWrapper = document.getElementById('logoWrapper');

                if (tpl === 'dana_receive') {
                    if (logoWrapper) logoWrapper.innerHTML = '<div class="bg-[#118EEA] rounded-full w-[76px] h-[76px] flex items-center justify-center shadow-[0_2px_8px_rgba(17,142,234,0.3)] mx-auto"><svg width="46" height="46" viewBox="0 0 48 48" fill="none"><rect x="8" y="10" width="32" height="28" rx="5" fill="white"/><rect x="13" y="15" width="22" height="2.5" rx="1.25" fill="#118EEA"/><path d="M28 20.5C28 18.567 29.567 17 31.5 17H40V31H31.5C29.567 31 28 29.433 28 27.5V20.5Z" fill="white" stroke="#118EEA" stroke-width="2"/><circle cx="35" cy="24" r="2.2" fill="#118EEA"/></svg></div>';
                    if (mainTitle) mainTitle.textContent = `Terima Uang ${formatCurrency(amount)} dari ${merchant}`;
                    if (totLab) totLab.textContent = 'Total Terima';
                    const statusLab = document.querySelector('#tpl_dana .status-label');
                    if (statusLab) statusLab.textContent = 'Berhasil diterima';
                } else {
                    if (logoWrapper) logoWrapper.innerHTML = '<img src="<?= base_url('assets/proof/qris_logo.png') ?>" alt="QRIS logo" class="w-28 sm:w-28 h-auto object-contain mx-auto">';
                    if (mainTitle) mainTitle.textContent = `Pembayaran ke ${merchant}`;
                    if (totLab) totLab.textContent = 'Total Bayar';
                    const statusLab = document.querySelector('#tpl_dana .status-label');
                    if (statusLab) statusLab.textContent = 'Transaksi berhasil!';
                }
                const totAmt = document.querySelector('#tpl_dana .total-amount');
                if (totAmt) totAmt.textContent = formatCurrency(amount);
                const dt = document.querySelector('#tpl_dana .tpl-dana-dates');
                if (dt) dt.textContent = `${date} • ${time}`;
                const id = document.querySelector('#tpl_dana .tpl-dana-id');
                if (id) {
                    const maskedId = maskDanaPhoneNumber(receiver) || '0857•••5875';
                    id.textContent = `ID DANA ${maskedId}`;
                }
            }

            // ── GOPAY LIGHT TEMPLATE ──
            if (tpl.startsWith('gopay_light')) {
                const r = document.getElementById('tpl_gopay_light');
                if (r) {
                    r.classList.remove('hidden');
                    r.style.display = 'block';
                }
                const merchant = document.getElementById('glName').value;
                const receiver = document.getElementById('glNumber').value;
                const amount = document.getElementById('glAmount').value;
                const now = getCurrentDateTime();
                const date = now.date;
                const time = now.time;
                const trx = generateTrxId('GL');

                document.querySelectorAll('#tpl_gopay_light .tpl-amount').forEach(el => el.textContent = formatCurrency(amount));
                const tot = document.querySelector('#tpl_gopay_light .tpl-total');
                if (tot) tot.textContent = formatCurrency(amount);
                const mer = document.querySelector('#tpl_gopay_light .tpl-merchant');
                if (mer) mer.textContent = (tpl === 'gopay_light_receive') ? `Diterima dari ${merchant}` : `Pembayaran ke ${merchant}`;
                const num = document.querySelector('#tpl_gopay_light .tpl-number');
                if (num) num.textContent = `GoPay ${receiver.startsWith('0') ? receiver : (receiver.startsWith('0') ? '****' + receiver.slice(-4) : receiver)}`;
                const tm = document.querySelector('#tpl_gopay_light .tpl-time');
                if (tm) tm.textContent = time;
                const dt = document.querySelector('#tpl_gopay_light .tpl-date');
                if (dt) dt.textContent = date;
                const tr = document.querySelector('#tpl_gopay_light .tpl-trx');
                if (tr) tr.textContent = trx;
            }

            // ── GOPAY DARK TEMPLATE ──
            if (tpl.startsWith('gopay_dark')) {
                const r = document.getElementById('tpl_gopay_dark');
                if (r) {
                    r.classList.remove('hidden');
                    r.style.display = 'block';
                }
                const merchant = document.getElementById('gdType').value;
                const receiver = document.getElementById('gdVia').value;
                const amount = document.getElementById('gdAmount').value;
                const now = getCurrentDateTime();
                const date = now.date;
                const time = now.time;
                const trx = generateTrxId('GD');

                document.querySelectorAll('#tpl_gopay_dark .tpl-amount').forEach(el => el.textContent = formatCurrency(amount));
                const tot = document.querySelector('#tpl_gopay_dark .tpl-total');
                if (tot) tot.textContent = formatCurrency(amount);
                const mer = document.querySelector('#tpl_gopay_dark .tpl-merchant');
                if (mer) mer.textContent = merchant;
                const num = document.querySelector('#tpl_gopay_dark .tpl-number');
                if (num) num.textContent = receiver;
                const tm = document.querySelector('#tpl_gopay_dark .tpl-time');
                if (tm) tm.textContent = time;
                const dt = document.querySelector('#tpl_gopay_dark .tpl-date');
                if (dt) dt.textContent = date;
                const trb = document.querySelector('#tpl_gopay_dark .tpl-trx-box');
                if (trb) trb.textContent = trx;
                const trl = document.querySelector('#tpl_gopay_dark .tpl-trx-line');
                if (trl) trl.textContent = trx;
            }

            // ── BCA NOTIFIKASI TEMPLATE ──
            if (tpl === 'bca_notif') {
                const r = document.getElementById('tpl_bca');
                if (r) {
                    r.classList.remove('hidden');
                    r.style.display = 'block';
                }

                const amount = document.getElementById('bcaAmount').value;
                const sender = document.getElementById('bcaSender').value;
                const category = document.getElementById('bcaCategory').value;
                const time = document.getElementById('bcaTime').value;
                const dateVal = document.getElementById('bcaDate').value;
                const notifTime = document.getElementById('bcaNotifTime').value;

                // Bind content values
                const elTime = document.getElementById('bca_preview_time');
                if (elTime) elTime.textContent = time;

                const dateParts = dateVal.split(',').map(s => s.trim());
                const elDay = document.getElementById('bca_preview_day');
                const elDate = document.getElementById('bca_preview_date');
                if (dateParts.length >= 2) {
                    if (elDay) elDay.textContent = dateParts[0];
                    if (elDate) elDate.textContent = dateParts.slice(1).join(' ');
                } else {
                    if (elDay) elDay.textContent = '';
                    if (elDate) elDate.textContent = dateVal;
                }

                // iOS Full Date & Time
                const now = new Date();
                const daysFullIndo = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                const monthsFullIndo = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                const elIosDate = document.getElementById('bca_preview_ios_date');
                const elIosTime = document.getElementById('bca_preview_ios_time');
                if (elIosDate) elIosDate.textContent = `${daysFullIndo[now.getDay()]}, ${now.getDate()} ${monthsFullIndo[now.getMonth()]}`;
                if (elIosTime) elIosTime.textContent = time;

                const elBarClock = document.getElementById('bca_bar_clock');
                if (elBarClock) elBarClock.textContent = time;

                const elNotifTime = document.getElementById('bca_preview_notif_time');
                if (elNotifTime) elNotifTime.textContent = notifTime;

                const elAmt = document.getElementById('bca_preview_amount');
                if (elAmt) elAmt.textContent = formatBcaCurrency(amount);

                const elSender = document.getElementById('bca_preview_sender');
                if (elSender) elSender.textContent = sender;

                const elCat = document.getElementById('bca_preview_category');
                if (elCat) elCat.textContent = category;

                // ══ WALLPAPER & PHONE MODEL VISUAL CONTROLLER (BCA) ══
                const wrapperBca = document.getElementById('wrapper_bca');
                const islandIphone = document.getElementById('bca_island_iphone');
                const islandNotch = document.getElementById('bca_island_notch');
                const islandPunch = document.getElementById('bca_island_punchhole');
                const islandDrop = document.getElementById('bca_island_waterdrop');
                const dynamicBar = document.getElementById('bca_dynamic_status_bar');
                const headerIos = document.getElementById('bca_header_ios');
                const headerAndroid = document.getElementById('bca_header_android');
                const bottomIos = document.getElementById('bca_bottom_ios');
                const bottomAndroid = document.getElementById('bca_bottom_android');
                const defaultSpacer = document.getElementById('bca_default_spacer');
                const contentTop = document.getElementById('bca_content_top');

                // Apply Wallpaper for BCA
                if (currentWallpaper === 'default') {
                    if (currentPhoneModel === 'oppo') {
                        wrapperBca.style.background = "url('<?= base_url('assets/proof/bg_bca_fully_clean.jpg') ?>') no-repeat center top";
                        wrapperBca.style.backgroundSize = "cover";
                    } else if (currentPhoneModel === 'iphone' || currentPhoneModel === 'iphone_notch') {
                        wrapperBca.style.background = "linear-gradient(180deg, #111827 0%, #1f2937 45%, #374151 100%)";
                        wrapperBca.style.backgroundSize = "cover";
                    } else if (currentPhoneModel === 'samsung') {
                        wrapperBca.style.background = "linear-gradient(180deg, #080d1a 0%, #131b2e 45%, #1b263d 100%)";
                        wrapperBca.style.backgroundSize = "cover";
                    } else if (currentPhoneModel === 'xiaomi') {
                        wrapperBca.style.background = "linear-gradient(180deg, #0b111e 0%, #151f33 45%, #1e2c47 100%)";
                        wrapperBca.style.backgroundSize = "cover";
                    } else if (currentPhoneModel === 'vivo') {
                        wrapperBca.style.background = "linear-gradient(180deg, #090e1a 0%, #131d31 45%, #1b2944 100%)";
                        wrapperBca.style.backgroundSize = "cover";
                    } else if (currentPhoneModel === 'realme') {
                        wrapperBca.style.background = "linear-gradient(180deg, #0d1117 0%, #161b22 45%, #21262d 100%)";
                        wrapperBca.style.backgroundSize = "cover";
                    } else if (currentPhoneModel === 'infinix') {
                        wrapperBca.style.background = "linear-gradient(180deg, #0a1014 0%, #111c24 45%, #172834 100%)";
                        wrapperBca.style.backgroundSize = "cover";
                    } else if (currentPhoneModel === 'pixel') {
                        wrapperBca.style.background = "linear-gradient(180deg, #121212 0%, #1e1e1e 45%, #2d2d2d 100%)";
                        wrapperBca.style.backgroundSize = "cover";
                    } else {
                        wrapperBca.style.background = "linear-gradient(180deg, #0b111e 0%, #151f33 45%, #1e2c47 100%)";
                        wrapperBca.style.backgroundSize = "cover";
                    }
                } else {
                    wrapperBca.style.background = wallpaperStyles[currentWallpaper].gradient;
                    wrapperBca.style.backgroundSize = "cover";
                }

                // Render dynamic status bar with distinct brand icons
                renderStatusBar('bca', time);

                // Apply Phone Model Layout for BCA
                if (currentPhoneModel === 'iphone') {
                    if (islandIphone) islandIphone.style.display = 'flex';
                    if (islandNotch) islandNotch.style.display = 'none';
                    if (islandPunch) islandPunch.style.display = 'none';
                    if (islandDrop) islandDrop.style.display = 'none';
                    if (dynamicBar) dynamicBar.style.display = 'flex';
                    if (headerIos) headerIos.style.display = 'flex';
                    if (headerAndroid) headerAndroid.style.display = 'none';
                    if (bottomIos) bottomIos.style.display = 'flex';
                    if (bottomAndroid) bottomAndroid.style.display = 'none';
                    if (defaultSpacer) defaultSpacer.style.display = 'none';
                    if (contentTop) { contentTop.style.paddingTop = '66px'; }
                } else if (currentPhoneModel === 'iphone_notch') {
                    if (islandIphone) islandIphone.style.display = 'none';
                    if (islandNotch) islandNotch.style.display = 'flex';
                    if (islandPunch) islandPunch.style.display = 'none';
                    if (islandDrop) islandDrop.style.display = 'none';
                    if (dynamicBar) dynamicBar.style.display = 'flex';
                    if (headerIos) headerIos.style.display = 'flex';
                    if (headerAndroid) headerAndroid.style.display = 'none';
                    if (bottomIos) bottomIos.style.display = 'flex';
                    if (bottomAndroid) bottomAndroid.style.display = 'none';
                    if (defaultSpacer) defaultSpacer.style.display = 'none';
                    if (contentTop) { contentTop.style.paddingTop = '66px'; }
                } else if (currentPhoneModel === 'oppo') {
                    if (islandIphone) islandIphone.style.display = 'none';
                    if (islandNotch) islandNotch.style.display = 'none';
                    if (islandPunch) islandPunch.style.display = 'none';
                    if (islandDrop) islandDrop.style.display = 'flex';
                    if (dynamicBar) dynamicBar.style.display = 'flex';
                    if (headerIos) headerIos.style.display = 'none';
                    if (headerAndroid) headerAndroid.style.display = 'flex';
                    if (bottomIos) bottomIos.style.display = 'none';
                    if (bottomAndroid) bottomAndroid.style.display = (currentWallpaper === 'default') ? 'none' : 'flex';
                    if (defaultSpacer) defaultSpacer.style.display = (currentWallpaper === 'default') ? 'block' : 'none';
                    if (contentTop) { contentTop.style.paddingTop = '56px'; }
                } else { // Android models: samsung, xiaomi, vivo, realme, infinix, pixel
                    if (islandIphone) islandIphone.style.display = 'none';
                    if (islandNotch) islandNotch.style.display = 'none';
                    if (islandPunch) islandPunch.style.display = 'block';
                    if (islandDrop) islandDrop.style.display = 'none';
                    if (dynamicBar) dynamicBar.style.display = 'flex';
                    if (headerIos) headerIos.style.display = 'none';
                    if (headerAndroid) headerAndroid.style.display = 'flex';
                    if (bottomIos) bottomIos.style.display = 'none';
                    if (bottomAndroid) bottomAndroid.style.display = 'flex';
                    if (defaultSpacer) defaultSpacer.style.display = 'none';
                    if (contentTop) { contentTop.style.paddingTop = '56px'; }
                }
            }

            // ── BRI NOTIFIKASI TEMPLATE ──
            if (tpl === 'bri_notif') {
                const r = document.getElementById('tpl_bri');
                if (r) {
                    r.classList.remove('hidden');
                    r.style.display = 'block';
                }

                const amount = document.getElementById('briAmount').value;
                const account = document.getElementById('briAccount').value;
                const trxDt = document.getElementById('briTrxDateTime').value;
                const ket = document.getElementById('briKet').value;
                const time = document.getElementById('briTime').value;
                const date = document.getElementById('briDate').value;
                const notifTime = document.getElementById('briNotifTime').value;

                // Bind content values
                const elTime = document.getElementById('bri_preview_time');
                if (elTime) elTime.textContent = time;

                const elDate = document.getElementById('bri_preview_date');
                if (elDate) elDate.textContent = date;

                // iOS Full Date & Time
                const now = new Date();
                const daysFullIndo = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                const monthsFullIndo = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                const elIosDate = document.getElementById('bri_preview_ios_date');
                const elIosTime = document.getElementById('bri_preview_ios_time');
                if (elIosDate) elIosDate.textContent = `${daysFullIndo[now.getDay()]}, ${now.getDate()} ${monthsFullIndo[now.getMonth()]}`;
                if (elIosTime) elIosTime.textContent = time;

                const elBarClock = document.getElementById('bri_bar_clock');
                if (elBarClock) elBarClock.textContent = time;

                const elNotifTime = document.getElementById('bri_preview_notif_time');
                if (elNotifTime) elNotifTime.textContent = notifTime;

                const elAmt = document.getElementById('bri_preview_amount');
                if (elAmt) elAmt.textContent = formatBriCurrency(amount);

                const elAcc = document.getElementById('bri_preview_account');
                if (elAcc) elAcc.textContent = account;

                const elTrxDt = document.getElementById('bri_preview_trx_datetime');
                if (elTrxDt) elTrxDt.textContent = trxDt;

                const elKet = document.getElementById('bri_preview_ket');
                if (elKet) elKet.textContent = ket;

                // ══ WALLPAPER & PHONE MODEL VISUAL CONTROLLER (BRI) ══
                const wrapperBri = document.getElementById('wrapper_bri');
                const islandIphone = document.getElementById('bri_island_iphone');
                const islandNotch = document.getElementById('bri_island_notch');
                const islandPunch = document.getElementById('bri_island_punchhole');
                const islandDrop = document.getElementById('bri_island_waterdrop');
                const dynamicBar = document.getElementById('bri_dynamic_status_bar');
                const headerIos = document.getElementById('bri_header_ios');
                const headerAndroid = document.getElementById('bri_header_android');
                const bottomIos = document.getElementById('bri_bottom_ios');
                const bottomAndroid = document.getElementById('bri_bottom_android');
                const defaultSpacer = document.getElementById('bri_default_spacer');
                const contentTop = document.getElementById('bri_content_top');

                // Apply Wallpaper & Typography Colors for BRI
                const briClock = document.getElementById('bri_preview_time');
                const briDate = document.getElementById('bri_preview_date');
                const briBell = document.getElementById('bri_header_bell');

                if (currentWallpaper === 'default') {
                    if (currentPhoneModel === 'oppo') {
                        wrapperBri.style.background = "url('<?= base_url('assets/proof/bg_bri_fully_clean.jpg') ?>') no-repeat center top";
                        wrapperBri.style.backgroundSize = "cover";
                        if (briClock) { briClock.style.color = '#202124'; briClock.style.textShadow = 'none'; }
                        if (briDate) { briDate.style.color = '#475569'; briDate.style.textShadow = 'none'; }
                        if (briBell) { briBell.style.color = '#374151'; }
                    } else if (currentPhoneModel === 'iphone' || currentPhoneModel === 'iphone_notch') {
                        wrapperBri.style.background = "linear-gradient(180deg, #111827 0%, #1f2937 45%, #374151 100%)";
                        wrapperBri.style.backgroundSize = "cover";
                        if (briClock) { briClock.style.color = '#FFFFFF'; briClock.style.textShadow = '0 2px 6px rgba(0,0,0,0.4)'; }
                        if (briDate) { briDate.style.color = '#E2E8F0'; briDate.style.textShadow = '0 1px 4px rgba(0,0,0,0.4)'; }
                        if (briBell) { briBell.style.color = '#FFFFFF'; }
                    } else if (currentPhoneModel === 'samsung') {
                        wrapperBri.style.background = "linear-gradient(180deg, #080d1a 0%, #131b2e 45%, #1b263d 100%)";
                        wrapperBri.style.backgroundSize = "cover";
                        if (briClock) { briClock.style.color = '#FFFFFF'; briClock.style.textShadow = '0 2px 6px rgba(0,0,0,0.4)'; }
                        if (briDate) { briDate.style.color = '#E2E8F0'; briDate.style.textShadow = '0 1px 4px rgba(0,0,0,0.4)'; }
                        if (briBell) { briBell.style.color = '#FFFFFF'; }
                    } else if (currentPhoneModel === 'xiaomi') {
                        wrapperBri.style.background = "linear-gradient(180deg, #0b111e 0%, #151f33 45%, #1e2c47 100%)";
                        wrapperBri.style.backgroundSize = "cover";
                        if (briClock) { briClock.style.color = '#FFFFFF'; briClock.style.textShadow = '0 2px 6px rgba(0,0,0,0.4)'; }
                        if (briDate) { briDate.style.color = '#E2E8F0'; briDate.style.textShadow = '0 1px 4px rgba(0,0,0,0.4)'; }
                        if (briBell) { briBell.style.color = '#FFFFFF'; }
                    } else if (currentPhoneModel === 'vivo') {
                        wrapperBri.style.background = "linear-gradient(180deg, #090e1a 0%, #131d31 45%, #1b2944 100%)";
                        wrapperBri.style.backgroundSize = "cover";
                        if (briClock) { briClock.style.color = '#FFFFFF'; briClock.style.textShadow = '0 2px 6px rgba(0,0,0,0.4)'; }
                        if (briDate) { briDate.style.color = '#E2E8F0'; briDate.style.textShadow = '0 1px 4px rgba(0,0,0,0.4)'; }
                        if (briBell) { briBell.style.color = '#FFFFFF'; }
                    } else if (currentPhoneModel === 'realme') {
                        wrapperBri.style.background = "linear-gradient(180deg, #0d1117 0%, #161b22 45%, #21262d 100%)";
                        wrapperBri.style.backgroundSize = "cover";
                        if (briClock) { briClock.style.color = '#FFFFFF'; briClock.style.textShadow = '0 2px 6px rgba(0,0,0,0.4)'; }
                        if (briDate) { briDate.style.color = '#E2E8F0'; briDate.style.textShadow = '0 1px 4px rgba(0,0,0,0.4)'; }
                        if (briBell) { briBell.style.color = '#FFFFFF'; }
                    } else if (currentPhoneModel === 'infinix') {
                        wrapperBri.style.background = "linear-gradient(180deg, #0a1014 0%, #111c24 45%, #172834 100%)";
                        wrapperBri.style.backgroundSize = "cover";
                        if (briClock) { briClock.style.color = '#FFFFFF'; briClock.style.textShadow = '0 2px 6px rgba(0,0,0,0.4)'; }
                        if (briDate) { briDate.style.color = '#E2E8F0'; briDate.style.textShadow = '0 1px 4px rgba(0,0,0,0.4)'; }
                        if (briBell) { briBell.style.color = '#FFFFFF'; }
                    } else if (currentPhoneModel === 'pixel') {
                        wrapperBri.style.background = "linear-gradient(180deg, #121212 0%, #1e1e1e 45%, #2d2d2d 100%)";
                        wrapperBri.style.backgroundSize = "cover";
                        if (briClock) { briClock.style.color = '#FFFFFF'; briClock.style.textShadow = '0 2px 6px rgba(0,0,0,0.4)'; }
                        if (briDate) { briDate.style.color = '#E2E8F0'; briDate.style.textShadow = '0 1px 4px rgba(0,0,0,0.4)'; }
                        if (briBell) { briBell.style.color = '#FFFFFF'; }
                    } else {
                        wrapperBri.style.background = "linear-gradient(180deg, #0b111e 0%, #151f33 45%, #1e2c47 100%)";
                        wrapperBri.style.backgroundSize = "cover";
                        if (briClock) { briClock.style.color = '#FFFFFF'; briClock.style.textShadow = '0 2px 6px rgba(0,0,0,0.4)'; }
                        if (briDate) { briDate.style.color = '#E2E8F0'; briDate.style.textShadow = '0 1px 4px rgba(0,0,0,0.4)'; }
                        if (briBell) { briBell.style.color = '#FFFFFF'; }
                    }
                } else {
                    wrapperBri.style.background = wallpaperStyles[currentWallpaper].gradient;
                    wrapperBri.style.backgroundSize = "cover";
                    if (briClock) { briClock.style.color = '#FFFFFF'; briClock.style.textShadow = '0 2px 6px rgba(0,0,0,0.4)'; }
                    if (briDate) { briDate.style.color = '#E2E8F0'; briDate.style.textShadow = '0 1px 4px rgba(0,0,0,0.4)'; }
                    if (briBell) { briBell.style.color = '#FFFFFF'; }
                }

                // Render dynamic status bar with distinct brand icons
                renderStatusBar('bri', time);

                // Show/hide Oppo bell icon on BRI
                if (briBell) {
                    briBell.style.display = (currentPhoneModel === 'oppo') ? 'block' : 'none';
                }

                // Apply Phone Model Layout for BRI
                if (currentPhoneModel === 'iphone') {
                    if (islandIphone) islandIphone.style.display = 'flex';
                    if (islandNotch) islandNotch.style.display = 'none';
                    if (islandPunch) islandPunch.style.display = 'none';
                    if (islandDrop) islandDrop.style.display = 'none';
                    if (dynamicBar) dynamicBar.style.display = 'flex';
                    if (headerIos) headerIos.style.display = 'flex';
                    if (headerAndroid) headerAndroid.style.display = 'none';
                    if (bottomIos) bottomIos.style.display = 'flex';
                    if (bottomAndroid) bottomAndroid.style.display = 'none';
                    if (defaultSpacer) defaultSpacer.style.display = 'none';
                    if (contentTop) { contentTop.style.paddingTop = '66px'; }
                } else if (currentPhoneModel === 'iphone_notch') {
                    if (islandIphone) islandIphone.style.display = 'none';
                    if (islandNotch) islandNotch.style.display = 'flex';
                    if (islandPunch) islandPunch.style.display = 'none';
                    if (islandDrop) islandDrop.style.display = 'none';
                    if (dynamicBar) dynamicBar.style.display = 'flex';
                    if (headerIos) headerIos.style.display = 'flex';
                    if (headerAndroid) headerAndroid.style.display = 'none';
                    if (bottomIos) bottomIos.style.display = 'flex';
                    if (bottomAndroid) bottomAndroid.style.display = 'none';
                    if (defaultSpacer) defaultSpacer.style.display = 'none';
                    if (contentTop) { contentTop.style.paddingTop = '66px'; }
                } else if (currentPhoneModel === 'oppo') {
                    if (islandIphone) islandIphone.style.display = 'none';
                    if (islandNotch) islandNotch.style.display = 'none';
                    if (islandPunch) islandPunch.style.display = 'none';
                    if (islandDrop) islandDrop.style.display = 'flex';
                    if (dynamicBar) dynamicBar.style.display = 'flex';
                    if (headerIos) headerIos.style.display = 'none';
                    if (headerAndroid) headerAndroid.style.display = 'flex';
                    if (bottomIos) bottomIos.style.display = 'none';
                    if (bottomAndroid) bottomAndroid.style.display = (currentWallpaper === 'default') ? 'none' : 'flex';
                    if (defaultSpacer) defaultSpacer.style.display = (currentWallpaper === 'default') ? 'block' : 'none';
                    if (contentTop) { contentTop.style.paddingTop = '56px'; }
                } else { // Android models: samsung, xiaomi, vivo, realme, infinix, pixel
                    if (islandIphone) islandIphone.style.display = 'none';
                    if (islandNotch) islandNotch.style.display = 'none';
                    if (islandPunch) islandPunch.style.display = 'block';
                    if (islandDrop) islandDrop.style.display = 'none';
                    if (dynamicBar) dynamicBar.style.display = 'flex';
                    if (headerIos) headerIos.style.display = 'none';
                    if (headerAndroid) headerAndroid.style.display = 'flex';
                    if (bottomIos) bottomIos.style.display = 'none';
                    if (bottomAndroid) bottomAndroid.style.display = 'flex';
                    if (defaultSpacer) defaultSpacer.style.display = 'none';
                    if (contentTop) { contentTop.style.paddingTop = '56px'; }
                }
            }

            // ── DANA NOTIFIKASI LOCKSCREEN TEMPLATE ──
            if (tpl === 'dana_notif') {
                const r = document.getElementById('tpl_dana_notif');
                if (r) {
                    r.classList.remove('hidden');
                    r.style.display = 'block';
                }

                const amount = document.getElementById('danaNotifAmount') ? document.getElementById('danaNotifAmount').value : 91269;
                const sender = document.getElementById('danaNotifSender') ? document.getElementById('danaNotifSender').value : 'MITRA DIGITAL';
                const notifType = document.getElementById('danaNotifType') ? document.getElementById('danaNotifType').value : 'topup';
                const time = document.getElementById('danaNotifTime') ? document.getElementById('danaNotifTime').value : '17.29';
                const dateVal = document.getElementById('danaNotifDate') ? document.getElementById('danaNotifDate').value : 'Min, Sep 6';
                const notifTimeAgo = document.getElementById('danaNotifTimeAgo') ? document.getElementById('danaNotifTimeAgo').value : 'sekarang';

                // Bind content values
                const elTime = document.getElementById('dana_notif_preview_time');
                if (elTime) elTime.textContent = time;

                const dateParts = dateVal.split(',').map(s => s.trim());
                const elDay = document.getElementById('dana_notif_preview_day');
                const elDate = document.getElementById('dana_notif_preview_date');
                if (dateParts.length >= 2) {
                    if (elDay) elDay.textContent = dateParts[0];
                    if (elDate) elDate.textContent = dateParts.slice(1).join(' ');
                } else {
                    if (elDay) elDay.textContent = '';
                    if (elDate) elDate.textContent = dateVal;
                }

                // iOS Full Date & Time
                const now = new Date();
                const daysFullIndo = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                const monthsFullIndo = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                const elIosDate = document.getElementById('dana_notif_preview_ios_date');
                const elIosTime = document.getElementById('dana_notif_preview_ios_time');
                if (elIosDate) elIosDate.textContent = `${daysFullIndo[now.getDay()]}, ${now.getDate()} ${monthsFullIndo[now.getMonth()]}`;
                if (elIosTime) elIosTime.textContent = time;

                const elTimeAgo = document.getElementById('dana_notif_preview_time_ago');
                if (elTimeAgo) elTimeAgo.textContent = notifTimeAgo;

                const elAmt = document.getElementById('dana_notif_preview_amount');
                if (elAmt) elAmt.textContent = formatCurrency(amount);

                const elSender = document.getElementById('dana_notif_preview_sender');
                if (elSender) elSender.textContent = sender;

                const elBody = document.getElementById('dana_notif_preview_body');
                if (elBody) {
                    if (notifType === 'transfer') {
                        elBody.innerHTML = `Berhasil kirim uang <span class="font-normal text-white">${formatCurrency(amount)}</span> ke <span class="text-white">${sender}</span>. Lihat detailnya di sini!`;
                    } else {
                        elBody.innerHTML = `Berhasil top up saldo <span class="font-normal text-white">${formatCurrency(amount)}</span> dari <span class="text-white">${sender}</span>. Lihat detailnya di sini!`;
                    }
                }

                // ══ WALLPAPER & PHONE MODEL VISUAL CONTROLLER (DANA NOTIF) ══
                const wrapperDanaNotif = document.getElementById('wrapper_dana_notif');
                const islandIphone = document.getElementById('dana_notif_island_iphone');
                const islandNotch = document.getElementById('dana_notif_island_notch');
                const islandPunch = document.getElementById('dana_notif_island_punchhole');
                const islandDrop = document.getElementById('dana_notif_island_waterdrop');
                const dynamicBar = document.getElementById('dana_notif_dynamic_status_bar');
                const headerIos = document.getElementById('dana_notif_header_ios');
                const headerAndroid = document.getElementById('dana_notif_header_android');
                const bottomIos = document.getElementById('dana_notif_bottom_ios');
                const bottomAndroid = document.getElementById('dana_notif_bottom_android');
                const defaultSpacer = document.getElementById('dana_notif_default_spacer');
                const contentTop = document.getElementById('dana_notif_content_top');
                const senyapRow = document.getElementById('dana_notif_senyap_row');

                // Apply Wallpaper
                if (currentWallpaper === 'default') {
                    if (currentPhoneModel === 'oppo') {
                        wrapperDanaNotif.style.background = "url('<?= base_url('assets/proof/bg_dana_fully_clean.jpg') ?>') no-repeat center top";
                        wrapperDanaNotif.style.backgroundSize = "cover";
                    } else if (currentPhoneModel === 'iphone' || currentPhoneModel === 'iphone_notch') {
                        wrapperDanaNotif.style.background = "linear-gradient(180deg, #111827 0%, #1f2937 45%, #374151 100%)";
                        wrapperDanaNotif.style.backgroundSize = "cover";
                    } else if (currentPhoneModel === 'samsung') {
                        wrapperDanaNotif.style.background = "linear-gradient(180deg, #080d1a 0%, #131b2e 45%, #1b263d 100%)";
                        wrapperDanaNotif.style.backgroundSize = "cover";
                    } else if (currentPhoneModel === 'xiaomi') {
                        wrapperDanaNotif.style.background = "linear-gradient(180deg, #0b111e 0%, #151f33 45%, #1e2c47 100%)";
                        wrapperDanaNotif.style.backgroundSize = "cover";
                    } else if (currentPhoneModel === 'vivo') {
                        wrapperDanaNotif.style.background = "linear-gradient(180deg, #090e1a 0%, #131d31 45%, #1b2944 100%)";
                        wrapperDanaNotif.style.backgroundSize = "cover";
                    } else if (currentPhoneModel === 'realme') {
                        wrapperDanaNotif.style.background = "linear-gradient(180deg, #0d1117 0%, #161b22 45%, #21262d 100%)";
                        wrapperDanaNotif.style.backgroundSize = "cover";
                    } else if (currentPhoneModel === 'infinix') {
                        wrapperDanaNotif.style.background = "linear-gradient(180deg, #0a1014 0%, #111c24 45%, #172834 100%)";
                        wrapperDanaNotif.style.backgroundSize = "cover";
                    } else if (currentPhoneModel === 'pixel') {
                        wrapperDanaNotif.style.background = "linear-gradient(180deg, #121212 0%, #1e1e1e 45%, #2c2c2c 100%)";
                        wrapperDanaNotif.style.backgroundSize = "cover";
                    } else {
                        wrapperDanaNotif.style.background = "linear-gradient(180deg, #111827 0%, #1f2937 45%, #374151 100%)";
                        wrapperDanaNotif.style.backgroundSize = "cover";
                    }
                } else {
                    wrapperDanaNotif.style.background = wallpaperStyles[currentWallpaper].gradient;
                    wrapperDanaNotif.style.backgroundSize = "cover";
                }

                renderStatusBar('dana_notif', time);

                if (senyapRow) senyapRow.style.display = (currentPhoneModel === 'oppo') ? 'flex' : 'none';
                if (currentPhoneModel === 'iphone') {
                    if (islandIphone) islandIphone.style.display = 'flex';
                    if (islandNotch) islandNotch.style.display = 'none';
                    if (islandPunch) islandPunch.style.display = 'none';
                    if (islandDrop) islandDrop.style.display = 'none';
                    if (dynamicBar) dynamicBar.style.display = 'flex';
                    if (headerIos) headerIos.style.display = 'flex';
                    if (headerAndroid) headerAndroid.style.display = 'none';
                    if (bottomIos) bottomIos.style.display = 'flex';
                    if (bottomAndroid) bottomAndroid.style.display = 'none';
                    if (defaultSpacer) defaultSpacer.style.display = 'none';
                    if (contentTop) { contentTop.style.paddingTop = '66px'; }
                } else if (currentPhoneModel === 'iphone_notch') {
                    if (islandIphone) islandIphone.style.display = 'none';
                    if (islandNotch) islandNotch.style.display = 'flex';
                    if (islandPunch) islandPunch.style.display = 'none';
                    if (islandDrop) islandDrop.style.display = 'none';
                    if (dynamicBar) dynamicBar.style.display = 'flex';
                    if (headerIos) headerIos.style.display = 'flex';
                    if (headerAndroid) headerAndroid.style.display = 'none';
                    if (bottomIos) bottomIos.style.display = 'flex';
                    if (bottomAndroid) bottomAndroid.style.display = 'none';
                    if (defaultSpacer) defaultSpacer.style.display = 'none';
                    if (contentTop) { contentTop.style.paddingTop = '66px'; }
                } else if (currentPhoneModel === 'oppo') {
                    if (islandIphone) islandIphone.style.display = 'none';
                    if (islandNotch) islandNotch.style.display = 'none';
                    if (islandPunch) islandPunch.style.display = 'none';
                    if (islandDrop) islandDrop.style.display = 'flex';
                    if (dynamicBar) dynamicBar.style.display = 'flex';
                    if (headerIos) headerIos.style.display = 'none';
                    if (headerAndroid) headerAndroid.style.display = 'flex';
                    if (bottomIos) bottomIos.style.display = 'none';
                    if (bottomAndroid) bottomAndroid.style.display = (currentWallpaper === 'default') ? 'none' : 'flex';
                    if (defaultSpacer) defaultSpacer.style.display = (currentWallpaper === 'default') ? 'block' : 'none';
                    if (contentTop) { contentTop.style.paddingTop = '56px'; }
                } else { // Android models: samsung, xiaomi, vivo, realme, infinix, pixel
                    if (islandIphone) islandIphone.style.display = 'none';
                    if (islandNotch) islandNotch.style.display = 'none';
                    if (islandPunch) islandPunch.style.display = 'block';
                    if (islandDrop) islandDrop.style.display = 'none';
                    if (dynamicBar) dynamicBar.style.display = 'flex';
                    if (headerIos) headerIos.style.display = 'none';
                    if (headerAndroid) headerAndroid.style.display = 'flex';
                    if (bottomIos) bottomIos.style.display = 'none';
                    if (bottomAndroid) bottomAndroid.style.display = 'flex';
                    if (defaultSpacer) defaultSpacer.style.display = 'none';
                    if (contentTop) { contentTop.style.paddingTop = '56px'; }
                }
            }
        }

        function setPresetAmount(val) {
            const tpl = document.getElementById('templateType').value;
            if (tpl === 'dana_notif') {
                const el = document.getElementById('danaNotifAmount');
                if (el) { el.value = val; }
            } else if (tpl.startsWith('dana')) {
                const el = document.getElementById('danaAmount');
                if (el) { el.value = val; }
            } else if (tpl.startsWith('gopay_light')) {
                const el = document.getElementById('glAmount');
                if (el) { el.value = val; }
            } else if (tpl.startsWith('gopay_dark')) {
                const el = document.getElementById('gdAmount');
                if (el) { el.value = val; }
            } else if (tpl === 'bca_notif') {
                const el = document.getElementById('bcaAmount');
                if (el) { el.value = val; }
            } else if (tpl === 'bri_notif') {
                const el = document.getElementById('briAmount');
                if (el) { el.value = val; }
            }
            updatePreview();
        }

        function randomizeDanaNotifSender() {
            const senders = ['MITRA DIGITAL', 'BANK MANDIRI', 'BANK BCA', 'BANK BRI', 'ALFAMART', 'INDOMARET', 'PT SOLUSI PEMBAYARAN', 'TOP UP VIA BCA'];
            const el = document.getElementById('danaNotifSender');
            if (el) {
                el.value = senders[Math.floor(Math.random() * senders.length)];
                updatePreview();
            }
        }

        function setDanaNotifSender(val) {
            const el = document.getElementById('danaNotifSender');
            if (el) {
                el.value = val;
                updatePreview();
            }
        }

        function refreshTimeNow() {
            randomizeStatusBarValues();
            const now = new Date();
            
            // Indonesian day and month names
            const daysIndo = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
            const monthsIndoShort = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
            
            const dayName = daysIndo[now.getDay()];
            const dayNum = String(now.getDate()).padStart(2, '0');
            const dayNumSingle = now.getDate();
            const monthShort = monthsIndoShort[now.getMonth()];
            const monthNum = String(now.getMonth() + 1).padStart(2, '0');
            const year = now.getFullYear();
            
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            
            const dotTime = `${hours}.${minutes}`;
            const colonTimeSec = `${hours}:${minutes}:${seconds}`;

            // BCA inputs:
            const bcaTimeEl = document.getElementById('bcaTime');
            const bcaDateEl = document.getElementById('bcaDate');
            if (bcaTimeEl) bcaTimeEl.value = dotTime;
            if (bcaDateEl) bcaDateEl.value = `${dayName}, ${monthShort} ${dayNumSingle}`;

            // BRI inputs:
            const briTimeEl = document.getElementById('briTime');
            const briDateEl = document.getElementById('briDate');
            const briTrxEl  = document.getElementById('briTrxDateTime');
            const briKetEl  = document.getElementById('briKet');
            if (briTimeEl) briTimeEl.value = dotTime;
            if (briDateEl) briDateEl.value = `${dayName}, ${dayNumSingle} ${monthShort}`;
            if (briTrxEl)  briTrxEl.value  = `${dayNum}/${monthNum}/${year} ${colonTimeSec}`;
            if (briKetEl && (!briKetEl.value || briKetEl.value.includes('DANA'))) {
                briKetEl.value = generateMandiriBriKet('random');
            }

            // DANA Notif inputs:
            const danaNotifTimeEl = document.getElementById('danaNotifTime');
            const danaNotifDateEl = document.getElementById('danaNotifDate');
            const danaNotifAgoEl  = document.getElementById('danaNotifTimeAgo');
            if (danaNotifTimeEl) danaNotifTimeEl.value = dotTime;
            if (danaNotifDateEl) danaNotifDateEl.value = `${dayName}, ${dayNumSingle} ${monthShort}`;
            if (danaNotifAgoEl && !danaNotifAgoEl.value) danaNotifAgoEl.value = 'sekarang';

            // Notification Status
            const bcaNotifEl = document.getElementById('bcaNotifTime');
            if (bcaNotifEl && !bcaNotifEl.value) bcaNotifEl.value = 'sekarang';
            const briNotifEl = document.getElementById('briNotifTime');
            if (briNotifEl && !briNotifEl.value) briNotifEl.value = 'sekarang';

            updatePreview();
        }

        function switchMobileView(view) {
            const editPanel = document.getElementById('studioEditorPanel');
            const prevPanel = document.getElementById('studioPreviewPanel');
            const btnEdit   = document.getElementById('btnTabEdit');
            const btnPrev   = document.getElementById('btnTabPreview');

            if (view === 'preview') {
                editPanel.classList.add('mobile-hidden');
                prevPanel.classList.remove('mobile-hidden');
                btnEdit.classList.remove('active');
                btnPrev.classList.add('active');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                editPanel.classList.remove('mobile-hidden');
                prevPanel.classList.add('mobile-hidden');
                btnEdit.classList.add('active');
                btnPrev.classList.remove('active');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        let fsPlaceholder = null;
        let fsActiveElem = null;

        function enterFullscreen() {
            const tpl = document.getElementById('templateType').value;
            let activeTag = '';
            if (tpl === 'dana_notif') activeTag = 'wrapper_dana_notif';
            else if (tpl.startsWith('dana')) activeTag = 'wrapper_dana';
            else if (tpl.startsWith('gopay_light')) activeTag = 'wrapper_gopay_light';
            else if (tpl.startsWith('gopay_dark')) activeTag = 'wrapper_gopay_dark';
            else if (tpl === 'bca_notif') activeTag = 'wrapper_bca';
            else if (tpl === 'bri_notif') activeTag = 'wrapper_bri';

            const elem = document.getElementById(activeTag);
            if (!elem) return;

            updatePreview();

            const req = elem.requestFullscreen || elem.webkitRequestFullscreen || elem.msRequestFullscreen;
            if (req) {
                try {
                    const p = req.call(elem);
                    if (p && p.catch) {
                        p.catch(() => openOverlayFs(elem));
                    }
                } catch (e) {
                    openOverlayFs(elem);
                }
            } else {
                openOverlayFs(elem);
            }
        }

        function openOverlayFs(elem) {
            let overlay = document.getElementById('mobileFsOverlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'mobileFsOverlay';
                overlay.style.cssText = 'position: fixed; inset: 0; z-index: 999999; overflow-y: auto; display: flex; flex-direction: column; align-items: center; margin: 0; padding: 0; width: 100%; height: 100%; -webkit-overflow-scrolling: touch;';
                document.body.appendChild(overlay);
            }

            const tpl = document.getElementById('templateType').value;
            if (tpl === 'dana_notif') {
                overlay.style.background = '#07090E';
            } else if (tpl.startsWith('dana')) {
                overlay.style.background = '#F5F6F8';
            } else if (tpl.startsWith('gopay_light')) {
                overlay.style.background = '#F0F2F5';
            } else if (tpl.startsWith('gopay_dark')) {
                overlay.style.background = '#121418';
            } else {
                overlay.style.background = '#07090E';
            }

            fsPlaceholder = document.createElement('div');
            elem.parentNode.insertBefore(fsPlaceholder, elem);
            fsActiveElem = elem;

            elem.classList.add('is-fullscreen-active');
            overlay.appendChild(elem);
            overlay.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function exitFs() {
            if (document.fullscreenElement || document.webkitFullscreenElement) {
                if (document.exitFullscreen) {
                    document.exitFullscreen().catch(() => {});
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                }
            }

            const overlay = document.getElementById('mobileFsOverlay');
            if (overlay && fsActiveElem && fsPlaceholder && fsPlaceholder.parentNode) {
                fsPlaceholder.parentNode.insertBefore(fsActiveElem, fsPlaceholder);
                fsPlaceholder.remove();
                fsPlaceholder = null;
                fsActiveElem.classList.remove('is-fullscreen-active');
                fsActiveElem = null;
                overlay.style.display = 'none';
                document.body.style.overflow = '';
            }
        }

        document.addEventListener('fullscreenchange', () => {
            if (!document.fullscreenElement) {
                exitFs();
            }
        });
        document.addEventListener('webkitfullscreenchange', () => {
            if (!document.webkitFullscreenElement) {
                exitFs();
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                exitFs();
            }
        });

        function getCurrentDateTime() {
            const now = new Date();
            const tanggal = now.toLocaleDateString('id-ID', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
            const waktu = now.toLocaleTimeString('id-ID', {
                hour: '2-digit',
                minute: '2-digit'
            });
            return { date: tanggal, time: waktu };
        }

        function generateTrxId(prefix = '') {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            let result = prefix;
            for (let i = 0; i < 12; i++) {
                result += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            return result;
        }

        function randomizeAllAndSetNow() {
            refreshTimeNow();
            const trxIdEl = document.getElementById('danaTrxId');
            if (trxIdEl && !trxIdEl.value) trxIdEl.value = generateTrxId('2024');
            const glTrxEl = document.getElementById('glTrxId');
            if (glTrxEl && !glTrxEl.value) glTrxEl.value = generateTrxId('GP-');
            const gdTrxEl = document.getElementById('gdTrxId');
            if (gdTrxEl && !gdTrxEl.value) gdTrxEl.value = generateTrxId('GP-');
            updatePreview();
        }

        window.onload = function() {
            detectAndApplyUserDevice();
            syncTemplateChips();
            updateFormDisplay();
            randomizeAllAndSetNow();
        };
    </script>
</body>
</html>
