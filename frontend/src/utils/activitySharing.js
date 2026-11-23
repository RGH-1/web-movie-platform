export const ACTIVITY_SHARING_FIELDS = [
    'share_activity',
    'share_ratings',
    'share_reviews',
    'share_public_lists',
];

export const ACTIVITY_SHARING_CHOICES = [
    {
        field: 'share_ratings',
        label: 'Share rating-only activity',
    },
    {
        field: 'share_reviews',
        label: 'Share written reviews and their star ratings',
    },
    {
        field: 'share_public_lists',
        label: 'Share public-list activity',
    },
];

export function readActivitySharing(profile = {}) {
    return Object.fromEntries(
        ACTIVITY_SHARING_FIELDS.map((field) => [
            field,
            profile[field] === true,
        ]),
    );
}

export function changedActivitySharing(values, profile) {
    const original = readActivitySharing(profile);

    return Object.fromEntries(
        ACTIVITY_SHARING_FIELDS
            .filter((field) => values[field] !== original[field])
            .map((field) => [field, values[field]]),
    );
}