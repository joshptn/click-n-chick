import { IconCheck } from "@tabler/icons-react";

import { PASSWORD_RULES } from "../../lib/passwordRules";


function PasswordChecklist({ password = "", id }) {
  return (
    <ul id={id} className="-mt-1.5 grid grid-cols-1 gap-1.5">
      {PASSWORD_RULES.map((rule) => {
        const met = rule.test(password);

        return (
          <li
            key={rule.label}
            className={`flex items-center gap-1.5 font-display text-[12px] transition-colors ${met ? "text-brand-600" : "text-[#8d8884]"}`}
          >
            {met ? (
              <IconCheck size={13} stroke={3} aria-hidden="true" className="shrink-0" />
            ) : (
              <span aria-hidden="true" className="mx-1 h-[5px] w-[5px] shrink-0 rounded-full bg-[#d9d3cb]" />
            )}
            {rule.label}
          </li>
        );
      })}
    </ul>
  );
}

export default PasswordChecklist;
