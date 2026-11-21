import { getReviewSentiment } from '../utils/reviewSentiment.js';

export default function ReviewSentiment({ review }) {
    const sentiment = getReviewSentiment(review);

    return (
        <p className="mt-2 mb-0" aria-live="polite" aria-atomic="true">
            <span className={`badge ${sentiment.className}`}>
                Text sentiment: {sentiment.label}
            </span>
        </p>
    );
}