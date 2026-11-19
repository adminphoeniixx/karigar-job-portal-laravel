// The job details added on 2026-11-19 (WorkIndia-style job form): their
// values, as the server takes them, and the i18n keys that label each one on
// the job form and the job pages. Mirrors App\Support\ReferenceData.

export const JOB_TYPES = ['full_time', 'part_time'] as const;
export const EXPERIENCE_LEVELS = ['any', 'fresher', 'experienced'] as const;
export const EDUCATION_LEVELS = ['Below 10th', '10th Pass', '12th Pass', 'ITI / Diploma', 'Graduate', 'Post Graduate'] as const;
export const ENGLISH_LEVELS = ['none', 'basic', 'good', 'fluent'] as const;
export const REACH = ['nearby', 'city'] as const;
export const FEE_PURPOSES = ['uniform', 'tools', 'security_deposit', 'training', 'exam', 'documentation', 'other'] as const;
export const ASSETS = ['aadhaar', 'pan', 'bank_account', 'smartphone', 'bike', 'driving_licence', 'own_tools', 'certificate'] as const;
export const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const;
export const URGENCIES = ['immediate', 'can_wait'] as const;
export const DEFAULT_WORK_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

export type ExperienceLevel = (typeof EXPERIENCE_LEVELS)[number];

// Education values carry spaces and slashes, which i18n paths cannot.
const EDUCATION_KEYS: Record<string, string> = {
    'Below 10th': 'below10',
    '10th Pass': 'tenth',
    '12th Pass': 'twelfth',
    'ITI / Diploma': 'iti',
    Graduate: 'graduate',
    'Post Graduate': 'postGraduate',
};

export const educationKey = (level: string) => `jobDetail.education.${EDUCATION_KEYS[level] ?? 'below10'}`;
export const jobTypeKey = (type: string) => `jobDetail.jobType.${type}`;
export const englishKey = (level: string) => `jobDetail.english.${level}`;
export const feePurposeKey = (purpose: string) => `jobDetail.feePurpose.${purpose}`;
export const assetKey = (asset: string) => `jobDetail.assets.${asset}`;
export const urgencyKey = (urgency: string) => `jobDetail.urgency.${urgency}`;
export const weekdayKey = (day: string) => `jobDetail.days.${day}`;

type Translate = (key: string, named?: Record<string, unknown>) => string;

/** Days as people say them: "Every day", "Mon–Sat", "Mon, Wed, Fri". */
export const daysLabel = (days: string[] | null | undefined, t: Translate): string | null => {
    const picked = WEEKDAYS.filter((d) => days?.includes(d));
    if (!picked.length) return null;
    if (picked.length === 7) return t('jobDetail.days.everyDay');

    const first = WEEKDAYS.indexOf(picked[0]);
    const last = WEEKDAYS.indexOf(picked[picked.length - 1]);
    if (picked.length >= 3 && last - first === picked.length - 1) {
        return `${t(weekdayKey(picked[0]))}–${t(weekdayKey(picked[picked.length - 1]))}`;
    }

    return picked.map((d) => t(weekdayKey(d))).join(', ');
};

/** "11:00 AM – 4:00 PM" from two "HH:MM" times. */
export const hoursLabel = (start?: string | null, end?: string | null): string | null => {
    if (!start || !end) return null;
    const time = (hm: string) => {
        const [h, m] = hm.split(':').map(Number);
        return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
    };
    return `${time(start)} – ${time(end)}`;
};

/** "18–40", "18+", "up to 40", or null. */
export const ageRange = (min?: number | string | null, max?: number | string | null): string | null => {
    const lo = min === '' || min === null || min === undefined ? null : Number(min);
    const hi = max === '' || max === null || max === undefined ? null : Number(max);
    if (lo !== null && hi !== null) return `${lo}–${hi}`;
    if (lo !== null) return `${lo}+`;
    if (hi !== null) return `≤ ${hi}`;
    return null;
};

/** any | fresher | experienced, read from the experience range as the server does. */
export const experienceLevelOf = (min: number | string | null | undefined, max: number | string | null | undefined): ExperienceLevel => {
    const lo = min === '' || min === null || min === undefined ? null : Number(min);
    const hi = max === '' || max === null || max === undefined ? null : Number(max);
    if (lo === null && hi === null) return 'any';
    if (lo === 0 && hi === 0) return 'fresher';
    return 'experienced';
};
