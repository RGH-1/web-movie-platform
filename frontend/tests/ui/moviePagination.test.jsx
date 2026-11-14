import { expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import MoviePagination from '../../src/components/MoviePagination';

it('jumps directly to page 45 and 500 and resets the input after navigation', () => {
    const change = vi.fn();
    const { rerender } = render(<MoviePagination page={1} totalPages={500} onPageChange={change} />);
    const input = screen.getByRole('spinbutton', { name: 'Page number' });
    fireEvent.change(input, { target: { value: '45' } });
    fireEvent.submit(input.form);
    expect(change).toHaveBeenLastCalledWith(45);
    rerender(<MoviePagination page={45} totalPages={500} onPageChange={change} />);
    expect(screen.getByRole('spinbutton')).toHaveValue(45);
    fireEvent.change(screen.getByRole('spinbutton'), { target: { value: '500' } });
    fireEvent.submit(screen.getByRole('spinbutton').form);
    expect(change).toHaveBeenLastCalledWith(500);
});

it.each(['', '0', '-1', '501', '1.5', '1'])('does not request invalid or unchanged page %s', (value) => {
    const change = vi.fn();
    render(<MoviePagination page={1} totalPages={500} onPageChange={change} />);
    const input = screen.getByRole('spinbutton');
    fireEvent.change(input, { target: { value } });
    fireEvent.submit(input.form);
    expect(change).not.toHaveBeenCalled();
});

it('disables boundary arrows and all controls while loading', () => {
    const change = vi.fn();
    const { rerender } = render(<MoviePagination page={1} totalPages={500} onPageChange={change} />);
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(change).toHaveBeenCalledWith(2);
    rerender(<MoviePagination page={500} totalPages={500} onPageChange={change} />);
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Previous' }));
    expect(change).toHaveBeenLastCalledWith(499);
    change.mockClear();
    rerender(<MoviePagination page={45} totalPages={500} loading onPageChange={change} />);
    expect(screen.getByRole('spinbutton')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
    fireEvent.submit(screen.getByRole('spinbutton').form);
    expect(change).not.toHaveBeenCalled();
});
