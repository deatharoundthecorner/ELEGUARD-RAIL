/**
 * EleGuard Rail Train Driver Alert Sounds & Safety Audio Governance
 * Milestone 9C Stage 4E.4.3-I Refinement:
 *
 * Operational Safety Mandate:
 * Cab audible sirens are permanently SUPPRESSED by default to avoid masking
 * critical safety audio channels in the locomotive cab (including VHF track
 * radios, AWS/TPWS audible warnings, and explosive track torpedoes/detonators).
 * Repeating siren alarms are strictly prohibited.
 */

let audioCtx = null;
let soundInterval = null;
// Audio is strictly suppressed by default for railway cab safety
let isMuted = true;

// Initialize audio context safely if explicitly requested
function initAudio() {
    if (!audioCtx) {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    if (audioCtx.state === 'suspended') {
        audioCtx.resume();
    }
}

// Single subdued reminder tone (repeating siren loop permanently removed)
function playSubduedReminderTone() {
    if (isMuted) return;
    initAudio();
    if (!audioCtx) return;

    try {
        const osc = audioCtx.createOscillator();
        const gainNode = audioCtx.createGain();

        osc.type = 'sine';
        osc.frequency.setValueAtTime(440, audioCtx.currentTime); // Gentle A4 note

        gainNode.gain.setValueAtTime(0, audioCtx.currentTime);
        gainNode.gain.linearRampToValueAtTime(0.05, audioCtx.currentTime + 0.05);
        gainNode.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.3);

        osc.connect(gainNode);
        gainNode.connect(audioCtx.destination);

        osc.start();
        osc.stop(audioCtx.currentTime + 0.35);
    } catch (e) {
        console.warn("Audio reminder suppressed:", e);
    }
}

// Monitor warning screen - repeating sirens are DISABLED
function checkAlertsForSound() {
    const audioStatus = document.getElementById('audioStatusText');
    if (audioStatus) {
        audioStatus.textContent = "🔇 SUPPRESSED (CAB SAFETY)";
        audioStatus.style.color = "var(--text-secondary)";
    }
}

function stopAlarm() {
    if (soundInterval) {
        clearInterval(soundInterval);
        soundInterval = null;
    }
}

function toggleAudio() {
    // Railway cab safety: repeating sirens remain disabled
    stopAlarm();
    const audioBtn = document.getElementById('toggleAudioBtn');
    const audioStatus = document.getElementById('audioStatusText');
    if (audioBtn) {
        audioBtn.textContent = "Cab Audio Suppressed by Safety Protocol";
        audioBtn.disabled = true;
        audioBtn.classList.add('muted');
    }
    if (audioStatus) {
        audioStatus.textContent = "🔇 SUPPRESSED (CAB SAFETY)";
    }
}

document.addEventListener('DOMContentLoaded', () => {
    stopAlarm();
    const audioBtn = document.getElementById('toggleAudioBtn');
    const audioStatus = document.getElementById('audioStatusText');
    
    if (audioBtn) {
        audioBtn.textContent = "Cab Audio Suppressed by Safety Protocol";
        audioBtn.disabled = true;
        audioBtn.classList.add('muted');
    }
    if (audioStatus) {
        audioStatus.textContent = "🔇 SUPPRESSED (CAB SAFETY)";
        audioStatus.style.color = "var(--text-secondary)";
    }
});
