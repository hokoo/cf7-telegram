if type == "array" and all(.[]; type == "object") then
	.
else
	error("Plugin Check strict JSON must be an array of finding objects")
end
