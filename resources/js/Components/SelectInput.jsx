import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';

/**
 * The select that Breeze's React kit does not ship.
 *
 * Same focus contract as TextInput so a form can hand it `isFocused`, and the
 * same class string so a page does not have to know which control it is
 * rendering.
 */
export default forwardRef(function SelectInput(
    { className = '', isFocused = false, children, ...props },
    ref,
) {
    const localRef = useRef(null);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    return (
        <select
            {...props}
            className={
                'rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 ' +
                className
            }
            ref={localRef}
        >
            {children}
        </select>
    );
});
