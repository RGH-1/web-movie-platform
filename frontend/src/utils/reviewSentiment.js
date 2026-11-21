const CLASSIFICATIONS = {
    positive: {
        state: 'positive',
        label: 'Positive',
        className: 'text-bg-success',
    },
    neutral: {
        state: 'neutral',
        label: 'Neutral',
        className: 'text-bg-secondary',
    },
    negative: {
        state: 'negative',
        label: 'Negative',
        className: 'text-bg-danger',
    },
};

export function getReviewSentiment(review) {
    const text = typeof review?.review_text === 'string'
        ? review.review_text.trim()
        : '';

    if (!text) {
        return {
            state: 'not_applicable',
            label: 'Not applicable',
            className: 'text-bg-secondary',
        };
    }

    if (review.sentiment_status === 'pending') {
        return {
            state: 'pending',
            label: 'Pending',
            className: 'text-bg-secondary',
        };
    }

    if (
        review.sentiment_status === 'completed' &&
        typeof review.sentiment_label === 'string' &&
        Object.hasOwn(CLASSIFICATIONS, review.sentiment_label)
    ) {
        return CLASSIFICATIONS[review.sentiment_label];
    }

    return {
        state: 'unavailable',
        label: 'Unavailable',
        className: 'text-bg-secondary',
    };
}