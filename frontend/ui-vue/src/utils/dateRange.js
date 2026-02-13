function toISODate(d) {
    const yyyy = d.getFullYear()
    const mm = String(d.getMonth() + 1).padStart(2, '0')
    const dd = String(d.getDate()).padStart(2, '0')
    return `${yyyy}-${mm}-${dd}`
}

function startOfDay(d) {
    const x = new Date(d)
    x.setHours(0, 0, 0, 0)
    return x
}

function endOfDay(d) {
    const x = new Date(d)
    x.setHours(23, 59, 59, 999)
    return x
}

function startOfWeekMonday(d) {
    const x = startOfDay(d)
    const day = x.getDay() // 0 Sun .. 6 Sat
    const diff = (day === 0 ? -6 : 1 - day) // Monday start
    x.setDate(x.getDate() + diff)
    return x
}

function endOfWeekMonday(d) {
    const s = startOfWeekMonday(d)
    const e = new Date(s)
    e.setDate(e.getDate() + 6)
    return endOfDay(e)
}

function startOfMonth(d) {
    return startOfDay(new Date(d.getFullYear(), d.getMonth(), 1))
}

function endOfMonth(d) {
    return endOfDay(new Date(d.getFullYear(), d.getMonth() + 1, 0))
}

function startOfQuarter(d) {
    const q = Math.floor(d.getMonth() / 3) // 0..3
    return startOfDay(new Date(d.getFullYear(), q * 3, 1))
}

function endOfQuarter(d) {
    const s = startOfQuarter(d)
    return endOfDay(new Date(s.getFullYear(), s.getMonth() + 3, 0))
}

export function computeRange(label, now = new Date()) {
    const today = startOfDay(now)

    switch (label) {
        case 'Today': {
            const s = today
            return { start: toISODate(s), end: toISODate(s), isCustom: false }
        }
        case 'Yesterday': {
            const y = new Date(today)
            y.setDate(y.getDate() - 1)
            return { start: toISODate(y), end: toISODate(y), isCustom: false }
        }
        case 'This Week': {
            const s = startOfWeekMonday(today)
            const e = endOfWeekMonday(today)
            return { start: toISODate(s), end: toISODate(e), isCustom: false }
        }
        case 'Last Week': {
            const s = startOfWeekMonday(today)
            s.setDate(s.getDate() - 7)
            const e = new Date(s)
            e.setDate(e.getDate() + 6)
            return { start: toISODate(s), end: toISODate(e), isCustom: false }
        }
        case '2 Weeks Ago': {
            const e = today
            const s = new Date(today)
            s.setDate(s.getDate() - 13) // inclusive 14 days
            return { start: toISODate(s), end: toISODate(e), isCustom: false }
        }
        case '3 Weeks Ago': {
            const e = today
            const s = new Date(today)
            s.setDate(s.getDate() - 20) // inclusive 21 days
            return { start: toISODate(s), end: toISODate(e), isCustom: false }
        }
        case 'This Month': {
            const s = startOfMonth(today)
            const e = endOfMonth(today)
            return { start: toISODate(s), end: toISODate(e), isCustom: false }
        }
        case 'Last Month': {
            const s = startOfMonth(today)
            s.setMonth(s.getMonth() - 1)
            const e = endOfMonth(s)
            return { start: toISODate(s), end: toISODate(e), isCustom: false }
        }
        case 'This Quarter': {
            const s = startOfQuarter(today)
            const e = endOfQuarter(today)
            return { start: toISODate(s), end: toISODate(e), isCustom: false }
        }
        case 'Last Quarter': {
            const s = startOfQuarter(today)
            s.setMonth(s.getMonth() - 3)
            const e = endOfQuarter(s)
            return { start: toISODate(s), end: toISODate(e), isCustom: false }
        }
        case 'Year To Date': {
            const s = startOfDay(new Date(today.getFullYear(), 0, 1))
            return { start: toISODate(s), end: toISODate(today), isCustom: false }
        }
        case 'Custom':
        default:
            return { start: null, end: null, isCustom: true }
    }
}
