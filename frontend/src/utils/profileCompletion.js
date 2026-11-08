export function readProfileCompletion(meta) {
  if (typeof meta?.profile_complete !== 'boolean' ||
      !Array.isArray(meta.incomplete_fields) ||
      !meta.incomplete_fields.every((field) => typeof field === 'string')) {
    return null;
  }

  return {
    complete: meta.profile_complete,
    fields: [...new Set(meta.incomplete_fields)],
  };
}

export function completionFromError(error) {
  if (error.response?.status !== 403 ||
      error.response.data?.code !== 'PROFILE_INCOMPLETE') {
    return null;
  }

  const fields = error.response.data.incomplete_fields;
  return {
    complete: false,
    fields: Array.isArray(fields) ? [...new Set(fields.filter((field) => typeof field === 'string'))] : [],
  };
}

export function completionFieldLabel(field) {
  return ({ name: 'Name', email: 'Email' })[field] ?? 'Required profile information';
}
