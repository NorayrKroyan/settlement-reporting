export function money(n) {
    let x = Number(n || 0)
    if (Object.is(x, -0)) x = 0
    return x.toLocaleString(undefined, { style: 'currency', currency: 'USD' })
}
