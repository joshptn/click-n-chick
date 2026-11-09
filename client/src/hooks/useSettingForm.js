import { useEffect, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import toast from "../components/app/Toast";
import { fetchSetting, settingKey } from "../lib/storeSettings";

/**
 * One section of Store Settings: the saved values and the Manager's edits.
 *
 * The edits are held apart from the saved values and are null until something
 * is typed, so a refetch updates an untouched form but never overwrites work in
 * progress, and Cancel only has to drop them.
 */
export function useSettingForm({ section, pick, validate }) {
  const queryClient = useQueryClient();
  const [draft, setDraft] = useState(null);
  const [errors, setErrors] = useState({});

  const query = useQuery({
    queryKey: settingKey(section),
    queryFn: ({ signal }) => fetchSetting(section, { signal }),
    staleTime: 30 * 1000,
    refetchOnWindowFocus: true,
  });

  const saved = query.data ? pick(query.data) : null;
  const values = draft ?? saved;
  const dirty = Boolean(draft && saved) && Object.keys(saved).some((key) => draft[key] !== saved[key]);

  useEffect(() => {
    if (!dirty) return undefined;

    const warn = (event) => event.preventDefault();

    window.addEventListener("beforeunload", warn);

    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);

  const bind = (key) => ({
    value: values?.[key] ?? "",
    invalid: Boolean(errors[key]),
    onChange: (event) => {
      const value = event.target.value;

      setDraft((current) => ({ ...(current ?? saved), [key]: value }));
      setErrors((current) => Object.fromEntries(Object.entries(current).filter(([name]) => name !== key)));
    },
  });

  const cancel = () => {
    setDraft(null);
    setErrors({});
  };

  const check = () => {
    const found = validate(values, query.data);

    setErrors(found);

    return Object.keys(found).length === 0;
  };

  const save = async (write) => {
    try {
      const response = await write(values);

      queryClient.setQueryData(settingKey(section), response);
      setDraft(null);
      toast.success(response.message, "Saved");

      return response;
    } catch (failure) {
      const fields = failure.payload?.errors;

      if (fields) setErrors(Object.fromEntries(Object.entries(fields).map(([key, messages]) => [key, messages[0]])));

      throw failure;
    }
  };

  return {
    data: query.data,
    saved,
    values,
    errors,
    dirty,
    isLoading: query.isLoading,
    isError: query.isError && !query.data,
    error: query.error,
    refetch: query.refetch,
    bind,
    cancel,
    check,
    save,
  };
}

export default useSettingForm;
