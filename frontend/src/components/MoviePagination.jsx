import { useId } from 'react';

function MoviePagination({ page, totalPages, loading = false, onPageChange, label = 'Movie results pagination' }) {
    const helpId = useId();

    function jumpToPage(event) {
        event.preventDefault();
        const input = event.currentTarget.elements.page;
        const nextPage = Number(input.value);
        if (!loading && input.reportValidity() && Number.isInteger(nextPage) &&
            nextPage >= 1 && nextPage <= totalPages && nextPage !== page) {
            onPageChange(nextPage);
        }
    }

    return (
        <nav className="d-flex flex-column align-items-center gap-2 mt-5" aria-label={label}>
            <form onSubmit={jumpToPage} className="input-group" style={{ maxWidth: '260px' }}>
                <button type="button" className="btn btn-outline-primary px-3" aria-label="Previous"
                    disabled={loading || page <= 1} onClick={() => onPageChange(page - 1)}>
                    <span aria-hidden="true">&#8249;</span>
                </button>
                <input key={`${page}-${totalPages}`} type="number" name="page"
                    aria-label="Page number" aria-describedby={helpId}
                    className="form-control text-center" defaultValue={page}
                    min={1} max={totalPages} step={1} required disabled={loading} />
                <button type="button" className="btn btn-outline-primary px-3" aria-label="Next"
                    disabled={loading || page >= totalPages} onClick={() => onPageChange(page + 1)}>
                    <span aria-hidden="true">&#8250;</span>
                </button>
                <button type="submit" className="visually-hidden-focusable" disabled={loading}>Go to page</button>
            </form>
            <span className="small text-body-secondary" aria-live="polite">Page {page} of {totalPages}</span>
            <span id={helpId} className="small text-body-secondary">Enter a page number and press Enter.</span>
        </nav>
    );
}

export default MoviePagination;
