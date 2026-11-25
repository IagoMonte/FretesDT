const stack = []

export const pushModal = (id) => { if (!stack.includes(id)) stack.push(id) }
export const removeModal = (id) => { const i = stack.indexOf(id); if (i >= 0) stack.splice(i, 1) }
export const isTopModal = (id) => stack.length > 0 && stack[stack.length - 1] === id
